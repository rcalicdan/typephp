<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Contract\ContractParser;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;
use TypePHP\Wrapper\IterableWrapper;

/**
 * Core runtime type checking engine for parameter validation, return type enforcement, and variable tracking.
 */
final class RuntimeTypeChecker
{
    private static ?TypeValidatorRegistry $registry = null;

    /**
     * Delegates generic template binding for class instances.
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?ErrorMessage
    {
        return TemplateManager::bindInstanceFromNode($instance, $typeNode, $context, $forceBind);
    }

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     *
     * Performs the following steps:
     * 1. Checks configuration toggles for inline variables.
     * 2. Normalizes class shape syntax (e.g. stdClass{id: int}) into intersection shapes.
     * 3. Tokenizes and parses the variable type string.
     * 4. Resolves FQCNs for file context.
     * 5. Evaluates whether the type category requires validation.
     * 6. Wraps callables or binds generic instances if applicable.
     * 7. Validates the assigned value against the parsed type node and returns value or ErrorMessage.
     */
    public static function checkVariable(mixed $value, string $typeString, string $varName, string $file): mixed
    {
        $rawConfig = Config::get()['inline_vars'] ?? [];
        /** @var array<string, bool> $config */
        $config = is_array($rawConfig) ? $rawConfig : [];

        $checkGenerics = (bool) ($config['generics'] ?? true);
        $checkCallables = (bool) ($config['callables'] ?? true);
        $checkScalars = (bool) ($config['scalars'] ?? false);
        $checkShapes = (bool) ($config['shapes'] ?? false);
        $checkObjects = (bool) ($config['objects'] ?? false);

        if (! $checkGenerics && ! $checkCallables && ! $checkScalars && ! $checkShapes && ! $checkObjects) {
            return $value;
        }

        try {
            $typeString = DocblockNormalizer::normalize($typeString);
            [$typeParser, $lexer] = self::getTypeParserComponents();

            $tokens = new TokenIterator($lexer->tokenize($typeString));
            $typeNode = $typeParser->parse($tokens);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
            }

            if (! self::shouldValidateType($typeNode, $config)) {
                return $value;
            }

            if ($typeNode instanceof CallableTypeNode || ($typeNode instanceof IdentifierTypeNode && strtolower($typeNode->name) === 'callable')) {
                return CallableWrapper::wrapTypeNode($typeNode, $value, "Variable \$$varName: Callback", self::getRegistry());
            }

            if ($typeNode instanceof GenericTypeNode && $checkGenerics && is_object($value)) {
                $err = self::bindInstanceFromNode($value, $typeNode, "Variable \$$varName", true);
                if ($err !== null) {
                    return $err;
                }
            }

            $registry = self::getRegistry();
            $err = $registry->validate($value, $typeNode, "Variable \$$varName");
            if ($err !== null) {
                return $err;
            }
        } catch (\Throwable $e) {
            if ($e instanceof ErrorMessage) {
                return $e;
            }
        }

        return $value;
    }

    /**
     * Evaluates class property validation dynamically based on configuration.
     * Supports both instance properties ($obj->prop) and static properties (Class::$prop).
     */
    public static function checkProperty(mixed $value, mixed $objectOrClass, string $propName, string $file): mixed
    {
        if (! is_object($objectOrClass) && ! is_string($objectOrClass)) {
            return $value;
        }

        $rawConfig = Config::get()['inline_vars'] ?? [];
        /** @var array<string, bool> $config */
        $config = is_array($rawConfig) ? $rawConfig : [];

        if (! ($config['properties'] ?? true)) {
            return $value;
        }

        $className = is_string($objectOrClass) ? $objectOrClass : get_class($objectOrClass);

        $typeNode = ContractParser::parseProperty($className, $propName);
        if ($typeNode === null) {
            return $value;
        }

        if (! self::shouldValidateType($typeNode, $config)) {
            return $value;
        }

        try {
            $registry = self::getRegistry();
            $err = $registry->validate($value, $typeNode, 'Property ' . $className . '::$' . $propName);
            if ($err !== null) {
                return $err;
            }
        } catch (\Throwable $e) {
            if ($e instanceof ErrorMessage) {
                return $e;
            }
        }

        return $value;
    }

    /**
     * Initializes generic call frames and returns a ScopeCleaner that pops the call frame on destruction.
     *
     * @param array<string, mixed> $vars
     */
    public static function setupScope(string $function, array $vars, ?object $thisObj = null): ErrorMessage|ScopeCleaner|null
    {
        $err = self::checkParams($function, $vars, $thisObj);

        if ($err !== null) {
            if ($thisObj === null) {
                TemplateManager::popCallFrame($function);
            }

            return $err;
        }

        return $thisObj === null ? new ScopeCleaner($function) : null;
    }

    /**
     * Validates all incoming parameters against the function or method's declared contract.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkParams(string $function, array $vars, ?object $thisObj = null): ?ErrorMessage
    {
        $contract = ContractParser::parse($function);
        if (count($contract['types']) === 0) {
            return null;
        }

        $registry = self::getRegistry();
        $templates = $contract['templates'];
        $aliases = $contract['aliases'];

        if ($thisObj === null) {
            TemplateManager::clearCallBindings($function, $templates);
        } elseif (str_contains($function, '::')) {
            $declaringClass = explode('::', $function, 2)[0];
            TemplateManager::resolveInheritedTemplates($thisObj, $declaringClass);
        }

        foreach ($contract['types'] as $paramName => $typeNode) {
            if (! \array_key_exists($paramName, $vars)) {
                continue;
            }

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            $typeNode = SpecialTypeResolver::resolve($typeNode, $function, $thisObj);
            $val = $vars[$paramName];

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            if ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates)) {
                $err = self::resolveClassStringTemplate($typeNode, $val, $paramName, $function, $thisObj, $templates);
                if ($err !== null) {
                    return $err;
                }

                continue;
            }

            if (self::getTemplateName($typeNode, $templates) !== null) {
                $err = self::resolveTemplateParam($typeNode, $val, $paramName, $function, $thisObj, $templates, $registry);
                if ($err !== null) {
                    return $err;
                }

                continue;
            }

            $err = $registry->validate($val, $typeNode, $function . '(): Argument $' . $paramName);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * Validates a function or method's return value against its declared contract and returns value or ErrorMessage.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkReturn(string $function, mixed $value, ?object $thisObj = null, array $vars = []): mixed
    {
        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            return $value;
        }

        $registry = self::getRegistry();

        $err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $function);
        if ($err !== null) {
            return $err;
        }

        $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);

        $aliases = $contract['aliases'] ?? [];
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $contract['templates']);
        $declaredTemplates = $contract['templates'];

        if (count($boundTemplates) > 0 || count($declaredTemplates) > 0) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $declaredTemplates);
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
        }

        $returnTypeNode = self::resolveConditionalReturnType($returnTypeNode, $vars, $boundTemplates, $registry);

        $err = $registry->validate($value, $returnTypeNode, $function . '(): Return value');
        if ($err !== null) {
            return $err;
        }

        if ($value instanceof \Traversable) {
            return self::wrapIterable($function, 'return', $value);
        }

        return $value;
    }

    /**
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(string $function, mixed $sendValue): mixed
    {
        if ($sendValue === null) {
            return null;
        }

        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            $sendTypeNode = $returnTypeNode->genericTypes[2] ?? null;

            if ($sendTypeNode !== null) {
                $registry = self::getRegistry();
                $err = $registry->validate($sendValue, $sendTypeNode, "$function(): Generator sent value (TSend)");
                if ($err !== null) {
                    throw new \TypeError($err->getMessage());
                }
            }
        }

        return $sendValue;
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue.
     */
    public static function checkYield(string $function, mixed $key, mixed $value): mixed
    {
        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            return $value;
        }

        $registry = self::getRegistry();
        $itemTypeNode = null;
        $keyTypeNode = null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            $typesCount = count($returnTypeNode->genericTypes);
            if ($typesCount === 1) {
                $itemTypeNode = $returnTypeNode->genericTypes[0];
            } elseif ($typesCount >= 2) {
                $keyTypeNode = $returnTypeNode->genericTypes[0];
                $itemTypeNode = $returnTypeNode->genericTypes[1];
            }
        } elseif ($returnTypeNode instanceof ArrayTypeNode) {
            $itemTypeNode = $returnTypeNode->type;
        }

        if ($key !== null && $keyTypeNode !== null) {
            $err = $registry->validate($key, $keyTypeNode, "$function(): Return iterator key");
            if ($err !== null) {
                throw new \TypeError($err->getMessage());
            }
        }

        if ($itemTypeNode !== null) {
            $err = $registry->validate($value, $itemTypeNode, "$function(): Return iterator value");
            if ($err !== null) {
                throw new \TypeError($err->getMessage());
            }
        }

        return $value;
    }

    /**
     * Wraps a callable parameter to intercept calls and validate inputs/returns dynamically.
     */
    public static function wrapCallable(string $function, string $paramName, mixed $callable): mixed
    {
        return CallableWrapper::wrap($function, $paramName, $callable, self::getRegistry());
    }

    /**
     * Wraps an iterable or generator parameter to lazily validate items during iteration.
     */
    public static function wrapIterable(string $function, string $paramName, mixed $iterable): mixed
    {
        return IterableWrapper::wrap($function, $paramName, $iterable, self::getRegistry());
    }

    /**
     * Infers a TypeNode AST representation from a raw PHP value.
     */
    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        return TemplateManager::inferTypeFromValue($value);
    }

    /**
     * Returns a singleton instance of the TypeValidatorRegistry.
     */
    private static function getRegistry(): TypeValidatorRegistry
    {
        return self::$registry ??= new TypeValidatorRegistry();
    }

    /**
     * Returns shared static instances of PHPStan's TypeParser and Lexer.
     *
     * @return array{TypeParser, Lexer}
     */
    private static function getTypeParserComponents(): array
    {
        /** @var TypeParser|null $typeParser */
        static $typeParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($typeParser === null || $lexer === null) {
            $configParser = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($configParser);
            $constExprParser = new ConstExprParser($configParser);
            $typeParser = new TypeParser($configParser, $constExprParser);
        }

        return [$typeParser, $lexer];
    }

    /**
     * Determines if a type node requires validation based on configured inline_vars toggles.
     *
     * @param array<string, bool> $config
     */
    private static function shouldValidateType(TypeNode $node, array $config): bool
    {
        if ($node instanceof CallableTypeNode) {
            return (bool) ($config['callables'] ?? true);
        }

        if ($node instanceof ObjectShapeNode || $node instanceof ArrayShapeNode || $node instanceof ArrayTypeNode) {
            return (bool) ($config['shapes'] ?? false);
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);

            if ($lower === 'mixed') {
                return false;
            }

            if ($lower === 'callable') {
                return (bool) ($config['callables'] ?? true);
            }

            if (in_array($lower, ['array', 'list', 'iterable'], true)) {
                return (bool) ($config['shapes'] ?? false);
            }

            if (in_array($lower, ['int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'null', 'true', 'false', 'scalar', 'numeric', 'positive-int', 'negative-int', 'non-empty-string', 'numeric-string', 'truthy', 'falsy'], true)) {
                return (bool) ($config['scalars'] ?? false);
            }

            return (bool) ($config['objects'] ?? false);
        }

        if ($node instanceof GenericTypeNode) {
            $lower = strtolower($node->type->name);
            if (in_array($lower, ['array', 'list', 'iterable'], true)) {
                return (bool) ($config['shapes'] ?? false);
            }

            if ((bool) ($config['generics'] ?? true)) {
                return true;
            }

            return (bool) ($config['objects'] ?? false);
        }

        if ($node instanceof NullableTypeNode) {
            return self::shouldValidateType($node->type, $config);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                if (self::shouldValidateType($t, $config)) {
                    return true;
                }
            }

            return false;
        }

        return (bool) ($config['scalars'] ?? false);
    }

    /**
     * Resolves parameter-based or template-based conditional return types if present.
     *
     * @param array<string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    private static function resolveConditionalReturnType(
        TypeNode $returnTypeNode,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry
    ): TypeNode {
        if ($returnTypeNode instanceof ConditionalTypeForParameterNode) {
            $paramName = ltrim($returnTypeNode->parameterName, '$');
            $paramValue = $vars[$paramName] ?? null;

            $targetErr = $registry->validate($paramValue, $returnTypeNode->targetType, 'condition');
            $isTargetMatch = ($targetErr === null);

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            return $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;
        }

        if ($returnTypeNode instanceof ConditionalTypeNode) {
            /** @var TypeNode|IdentifierTypeNode $subjectTypeNode */
            $subjectTypeNode = $returnTypeNode->subjectType;

            if ($subjectTypeNode instanceof IdentifierTypeNode && isset($boundTemplates[$subjectTypeNode->name])) {
                /** @var TypeNode $subjectTypeNode */
                $subjectTypeNode = $boundTemplates[$subjectTypeNode->name];
            }

            $subStr = (string) $subjectTypeNode;
            /** @var TypeNode $targetTypeNode */
            $targetTypeNode = $returnTypeNode->targetType;
            $targetStr = (string) $targetTypeNode;

            $isTargetMatch = ($subStr === $targetStr);
            if (! $isTargetMatch) {
                $isTargetMatch = ClassNameValidator::isValid($subStr) && ClassNameValidator::isValid($targetStr) &&
                    (class_exists($subStr) || interface_exists($subStr)) &&
                    (class_exists($targetStr) || interface_exists($targetStr)) &&
                    is_a($subStr, $targetStr, true);
            }

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            return $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;
        }

        return $returnTypeNode;
    }

    /**
     * Determines if a generic node represents a class-string<T> template.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function isClassStringTemplate(GenericTypeNode $typeNode, array $templates): bool
    {
        return isset($typeNode->genericTypes[0])
            && $typeNode->genericTypes[0] instanceof IdentifierTypeNode
            && strtolower($typeNode->type->name) === 'class-string'
            && isset($templates[$typeNode->genericTypes[0]->name]);
    }

    /**
     * Binds and validates a class-string<T> argument against declared template bounds.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveClassStringTemplate(GenericTypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates): ?ErrorMessage
    {
        /** @var IdentifierTypeNode $innerType */
        $innerType = $typeNode->genericTypes[0];
        $templateName = $innerType->name;
        $templateNode = $templates[$templateName];

        if (! TemplateManager::isBound($function, $thisObj, $templateName)) {
            if (! is_string($val) || ! ClassNameValidator::isValid($val) || (! class_exists($val) && ! interface_exists($val) && ! trait_exists($val) && ! enum_exists($val))) {
                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($val) . ' given');
            }

            if ($templateNode->bound !== null) {
                $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
                $boundName = $resolvedBound instanceof IdentifierTypeNode ? $resolvedBound->name : (string) $resolvedBound;
                if (! is_a($val, $boundName, true)) {
                    return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundName . ", '" . $val . "' given");
                }
            }

            TemplateManager::bindTemplate($function, $thisObj, $templateName, new IdentifierTypeNode($val));
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $thisObj, $templateName);
            $targetClass = $expectedTypeNode instanceof IdentifierTypeNode ? $expectedTypeNode->name : (string) $expectedTypeNode;

            if (! is_string($val) || ! is_a($val, $targetClass, true)) {
                $valStr = TypeFormatter::formatGivenValue($val);

                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a class-string of ' . $targetClass . ', ' . $valStr . ' given');
            }
        }

        return null;
    }

    /**
     * Extracts template name from an AST node if present in the function's declared templates.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function getTemplateName(TypeNode $typeNode, array $templates): ?string
    {
        if ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name])) {
            return $typeNode->name;
        }

        if ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name])) {
            return $typeNode->type->name;
        }

        return null;
    }

    /**
     * Binds a generic template parameter or validates an incoming argument against a previously bound template.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveTemplateParam(TypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        $templateName = self::getTemplateName($typeNode, $templates);
        if ($templateName === null || ! isset($templates[$templateName])) {
            return null;
        }

        $templateNode = $templates[$templateName];
        $isVariadic = $typeNode instanceof ArrayTypeNode;

        if (! TemplateManager::isBound($function, $thisObj, $templateName)) {
            $sampleVal = ($isVariadic && is_array($val)) ? ($val[0] ?? null) : $val;
            $inferredType = TemplateManager::inferTypeFromValue($sampleVal);

            if ($templateNode->bound !== null) {
                $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
                $err = $registry->validate($sampleVal, $resolvedBound, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ')');
                if ($err !== null) {
                    return $err;
                }
            }

            TemplateManager::bindTemplate($function, $thisObj, $templateName, $inferredType);

            if ($isVariadic && is_array($val)) {
                foreach ($val as $idx => $item) {
                    $err = $registry->validate($item, $inferredType, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $inferredType . ')');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $thisObj, $templateName);
            if ($expectedTypeNode === null) {
                return null;
            }

            if ($isVariadic && is_array($val)) {
                foreach ($val as $idx => $item) {
                    $err = $registry->validate($item, $expectedTypeNode, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $expectedTypeNode . ')');
                    if ($err !== null) {
                        return $err;
                    }
                }
            } else {
                $err = $registry->validate($val, $expectedTypeNode, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ' = ' . $expectedTypeNode . ')');
                if ($err !== null) {
                    return $err;
                }
            }
        }

        return null;
    }
}
