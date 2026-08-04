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

final class RuntimeTypeChecker
{
    private static ?TypeValidatorRegistry $registry = null;

    private static function getRegistry(): TypeValidatorRegistry
    {
        return self::$registry ??= new TypeValidatorRegistry();
    }

    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?\TypeError
    {
        return TemplateManager::bindInstanceFromNode($instance, $typeNode, $context, $forceBind);
    }

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     */
    public static function checkVariable(mixed $value, string $typeString, string $varName, string $file): mixed
    {
        $config = Config::get()['inline_vars'] ?? [];
        $checkGenerics = $config['generics'] ?? true;

        // Fast return if absolutely everything is disabled
        if (!($config['generics'] ?? true) && !($config['callables'] ?? true) && !($config['scalars'] ?? false) && !($config['shapes'] ?? false) && !($config['objects'] ?? false)) {
            return $value;
        }

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

        try {
            $tokens = new TokenIterator($lexer->tokenize($typeString));
            $typeNode = $typeParser->parse($tokens);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
            }

            if (!self::shouldValidateType($typeNode, $config)) {
                return $value; // Skip validation entirely if disabled by config or type is 'mixed'
            }

            // Special Handling for Callables
            if ($typeNode instanceof CallableTypeNode || (isset($typeNode->name) && strtolower($typeNode->name) === 'callable')) {
                return CallableWrapper::wrapTypeNode($typeNode, $value, "Variable \$$varName: Callback", self::getRegistry());
            }

            // Special Handling for Generics Instance Binding
            if ($typeNode instanceof GenericTypeNode && $checkGenerics && is_object($value)) {
                $err = self::bindInstanceFromNode($value, $typeNode, "Variable \$$varName", true);
                if ($err !== null) throw $err;
            }

            // Standard Validation for everything else (Scalars, Shapes, Objects, etc.)
            $registry = self::getRegistry();
            $err = $registry->validate($value, $typeNode, "Variable \$$varName");
            if ($err !== null) {
                throw $err;
            }

        } catch (\Throwable $e) {
            if ($e instanceof \TypeError) throw $e;
        }

        return $value;
    }

    private static function shouldValidateType(TypeNode $node, array $config): bool
    {
        if ($node instanceof CallableTypeNode) return $config['callables'] ?? true;
        if ($node instanceof ArrayShapeNode || $node instanceof ArrayTypeNode) return $config['shapes'] ?? false;
        
        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);
            
            // Fast-path: Never waste CPU cycles validating `mixed`
            if ($lower === 'mixed') return false; 
            
            if ($lower === 'callable') return $config['callables'] ?? true;
            if (in_array($lower, ['array', 'list', 'iterable'])) return $config['shapes'] ?? false;
            
            if (in_array($lower, ['int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'null', 'true', 'false', 'scalar', 'numeric', 'positive-int', 'negative-int', 'non-empty-string', 'numeric-string', 'truthy', 'falsy'])) {
                return $config['scalars'] ?? false;
            }
            
            return $config['objects'] ?? false;
        }

        if ($node instanceof GenericTypeNode) {
            $lower = strtolower($node->type->name);
            if (in_array($lower, ['array', 'list', 'iterable'])) return $config['shapes'] ?? false;
            if ($config['generics'] ?? true) return true; 
            return $config['objects'] ?? false;
        }

        if ($node instanceof NullableTypeNode) {
            return self::shouldValidateType($node->type, $config);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                if (self::shouldValidateType($t, $config)) return true;
            }
            return false;
        }

        return $config['scalars'] ?? false;
    }

    /**
     * Initializes generic call frames and returns a ScopeCleaner that pops the frame on destruction
     */
    public static function setupScope(string $function, array $vars, ?object $thisObj = null): \TypeError|ScopeCleaner|null
    {
        $err = self::checkParams($function, $vars, $thisObj);
        
        if ($err !== null) {
            // CheckParams pushed the frame before evaluating, so we must pop it immediately on failure
            if ($thisObj === null) {
                TemplateManager::popCallFrame($function);
            }
            return $err;
        }

        return $thisObj === null ? new ScopeCleaner($function) : null;
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function checkParams(string $function, array $vars, ?object $thisObj = null): ?\TypeError
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

            // Resolve class-string<T>
            if ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates)) {
                $err = self::resolveClassStringTemplate($typeNode, $val, $paramName, $function, $thisObj, $templates);
                if ($err !== null) {
                    return $err;
                }

                continue;
            }

            // Resolve @template T & variadic templates (@param T ...$items)
            if (self::getTemplateName($typeNode, $templates) !== null) {
                $err = self::resolveTemplateParam($typeNode, $val, $paramName, $function, $thisObj, $templates, $registry);
                if ($err !== null) {
                    return $err;
                }

                continue;
            }

            // Standard Type Validation
            $err = $registry->validate($val, $typeNode, $function . '(): Argument $' . $paramName);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
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

        // Strict $this identity check
        $err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $function);
        if ($err !== null) {
            throw $err;
        }

        // Resolve special type names (self, static, parent, $this)
        $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);

        // Resolve aliases (@phpstan-type)
        $aliases = $contract['aliases'] ?? [];
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        // Substitute template placeholders (T[] -> int[], or unbound T -> Animal/mixed)
        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $contract['templates']);
        $declaredTemplates = $contract['templates'];

        if (count($boundTemplates) > 0 || count($declaredTemplates) > 0) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $declaredTemplates);
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
        }

        // Parameter-based Conditional Return Types
        if ($returnTypeNode instanceof ConditionalTypeForParameterNode) {
            $paramName = ltrim($returnTypeNode->parameterName, '$');
            $paramValue = $vars[$paramName] ?? null;

            $targetErr = $registry->validate($paramValue, $returnTypeNode->targetType, 'condition');
            $isTargetMatch = ($targetErr === null);

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $effectiveReturnTypeNode = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            $err = $registry->validate($value, $effectiveReturnTypeNode, $function . '(): Return value');
            if ($err !== null) {
                throw $err;
            }

            return $value;
        }

        // Template-based Conditional Return Types
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

            $effectiveReturnTypeNode = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            $err = $registry->validate($value, $effectiveReturnTypeNode, $function . '(): Return value');
            if ($err !== null) {
                throw $err;
            }

            return $value;
        }

        // Standard Return Validation
        $err = $registry->validate($value, $returnTypeNode, $function . '(): Return value');
        if ($err !== null) {
            throw $err;
        }

        if ($value instanceof \Traversable) {
            return self::wrapIterable($function, 'return', $value);
        }

        return $value;
    }

    public static function checkSend(string $function, mixed $sendValue): mixed
    {
        if ($sendValue === null) {
            return null;
        }

        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            // Generator<TKey, TValue, TSend, TReturn> -> index 2 is TSend
            $sendTypeNode = $returnTypeNode->genericTypes[2] ?? null;

            if ($sendTypeNode !== null) {
                $registry = self::getRegistry();
                $err = $registry->validate($sendValue, $sendTypeNode, "$function(): Generator sent value (TSend)");
                if ($err !== null) {
                    throw $err;
                }
            }
        }

        return $sendValue;
    }

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
                throw $err;
            }
        }

        if ($itemTypeNode !== null) {
            $err = $registry->validate($value, $itemTypeNode, "$function(): Return iterator value");
            if ($err !== null) {
                throw $err;
            }
        }

        return $value;
    }

    public static function wrapCallable(string $function, string $paramName, mixed $callable): mixed
    {
        return CallableWrapper::wrap($function, $paramName, $callable, self::getRegistry());
    }

    public static function wrapIterable(string $function, string $paramName, mixed $iterable): mixed
    {
        return IterableWrapper::wrap($function, $paramName, $iterable, self::getRegistry());
    }

    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        return TemplateManager::inferTypeFromValue($value);
    }

    /**
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
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveClassStringTemplate(GenericTypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates): ?\TypeError
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
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveTemplateParam(TypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates, TypeValidatorRegistry $registry): ?\TypeError
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
                // Resolve FQCN for the bound before checking
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
                return null; // Should ideally never happen if isBound() is true
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