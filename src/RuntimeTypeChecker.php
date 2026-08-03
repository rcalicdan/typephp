<?php

declare(strict_types=1);

namespace TypePHP;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Validator\TypeValidatorRegistry;

final class RuntimeTypeChecker
{
    /**
     * @var array<string, array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}>
     */
    private static array $contractCache = [];

    /**
     * Uses object references as keys. Solves spl_object_id collisions and memory leaks!
     * @var \WeakMap<object, array<string, TypeNode>>|null
     */
    private static ?\WeakMap $instanceTemplateBindings = null;

    /**
     * Tracks templates for pure functions and static methods.
     * @var array<string, TypeNode>
     */
    private static array $callTemplateBindings = [];

    private static ?TypeValidatorRegistry $registry = null;

    /**
     * Recursively resolves 'self', 'static', 'parent', and '$this' to actual class names.
     */
    private static function resolveSpecialTypeNames(TypeNode $node, string $function, ?object $thisObj = null): TypeNode
    {
        $declaringClass = str_contains($function, '::') ? explode('::', $function, 2)[0] : null;
        $runtimeClass = $thisObj ? get_class($thisObj) : $declaringClass;

        if ($node instanceof ThisTypeNode) {
            if ($runtimeClass) {
                return new IdentifierTypeNode($runtimeClass);
            }
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);
            if ($lower === 'self' && $declaringClass) {
                return new IdentifierTypeNode($declaringClass);
            }
            if ($lower === 'static' && $runtimeClass) {
                return new IdentifierTypeNode($runtimeClass);
            }
            if ($lower === 'parent' && $declaringClass && get_parent_class($declaringClass)) {
                return new IdentifierTypeNode(get_parent_class($declaringClass));
            }
            if ($lower === '$this' && $runtimeClass) {
                return new IdentifierTypeNode($runtimeClass);
            }
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::resolveSpecialTypeNames($node->type, $function, $thisObj);
            $innerTypes = array_map(
                fn($t) => self::resolveSpecialTypeNames($t, $function, $thisObj),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $innerTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolveSpecialTypeNames($node->type, $function, $thisObj));
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolveSpecialTypeNames($node->type, $function, $thisObj));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn($t) => self::resolveSpecialTypeNames($t, $function, $thisObj),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn($t) => self::resolveSpecialTypeNames($t, $function, $thisObj),
                $node->types
            ));
        }

        return $node;
    }

    /**
     * Binds or validates instance template types using a GenericTypeNode (e.g. Collection<int>).
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?\TypeError
    {
        $className = $typeNode->type->name;
        if (in_array(strtolower($className), ['self', 'static', '$this'], true)) {
            $className = get_class($instance);
        }

        if (! is_a($instance, $className)) {
            return null;
        }

        try {
            $ref = new \ReflectionClass($className);
            $classDoc = $ref->getDocComment();

            if ($classDoc) {
                static $phpDocParser = null;
                static $lexer = null;

                if ($phpDocParser === null) {
                    $config = new ParserConfig(usedAttributes: []);
                    $lexer = new Lexer($config);
                    $constExprParser = new ConstExprParser($config);
                    $typeParser = new TypeParser($config, $constExprParser);
                    $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
                }

                $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                $classPhpDocNode = $phpDocParser->parse($classTokens);

                // Fetch all template tags and their declared variances
                $templates = [];
                $classVariances = [];

                foreach ($classPhpDocNode->getTags() as $tagNode) {
                    if ($tagNode->value instanceof TemplateTagValueNode) {
                        $templates[] = $tagNode->value;
                        $tagName = strtolower($tagNode->name);

                        if (str_contains($tagName, 'covariant')) {
                            $classVariances[$tagNode->value->name] = GenericTypeNode::VARIANCE_COVARIANT;
                        } elseif (str_contains($tagName, 'contravariant')) {
                            $classVariances[$tagNode->value->name] = GenericTypeNode::VARIANCE_CONTRAVARIANT;
                        } else {
                            $classVariances[$tagNode->value->name] = GenericTypeNode::VARIANCE_INVARIANT;
                        }
                    }
                }

                if (self::$instanceTemplateBindings === null) {
                    self::$instanceTemplateBindings = new \WeakMap();
                }

                foreach ($templates as $index => $templateTag) {
                    if (isset($typeNode->genericTypes[$index])) {
                        $expectedTypeNode = $typeNode->genericTypes[$index];

                        $usageVariance = $typeNode->variances[$index] ?? GenericTypeNode::VARIANCE_INVARIANT;
                        $declaredVariance = $classVariances[$templateTag->name] ?? GenericTypeNode::VARIANCE_INVARIANT;

                        // Usage-site explicit variance (covariant/contravariant) takes precedence over class default
                        $variance = ($usageVariance !== GenericTypeNode::VARIANCE_INVARIANT)
                            ? $usageVariance
                            : $declaredVariance;

                        $templateName = $templateTag->name;

                        if ($forceBind) {
                            if (! isset(self::$instanceTemplateBindings[$instance])) {
                                self::$instanceTemplateBindings[$instance] = [];
                            }
                            self::$instanceTemplateBindings[$instance][$templateName] = $expectedTypeNode;
                        } else {
                            // If an object has no template binding recorded, treat it as mixed (unbound)
                            $existingTypeNode = self::$instanceTemplateBindings[$instance][$templateName]
                                ?? new IdentifierTypeNode('mixed');

                            $valid = self::checkVariance(
                                $existingTypeNode,
                                $expectedTypeNode,
                                $variance
                            );

                            if (! $valid) {
                                return ErrorFactory::createError(
                                    $context . " expects {$className}<{$variance} {$expectedTypeNode}>, but {$className}<{$existingTypeNode}> was given"
                                );
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Evaluates generic variance rules (invariant, covariant, contravariant, bivariant)
     */
    private static function checkVariance(TypeNode $existing, TypeNode $expected, string $variance): bool
    {
        $existingStr = (string) $existing;
        $expectedStr = (string) $expected;

        if ($existingStr === $expectedStr) {
            return true;
        }

        // Bivariant (*) or mixed accepts any type
        if ($variance === GenericTypeNode::VARIANCE_BIVARIANT || $expectedStr === 'mixed') {
            return true;
        }

        $isSubclass = function (string $sub, string $super): bool {
            if ((class_exists($sub) || interface_exists($sub)) && (class_exists($super) || interface_exists($super))) {
                return is_a($sub, $super, true);
            }

            return false;
        };

        // Covariant: existing type (e.g. Dog) must be a subtype of expected type (e.g. Animal)
        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            return $isSubclass($existingStr, $expectedStr);
        }

        // Contravariant: expected type (e.g. Animal) must be a subtype of existing type (e.g. Dog)
        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            return $isSubclass($expectedStr, $existingStr);
        }

        return false;
    }

    /**
     * Pre-binds generic template types for an object instance using a @var type string.
     */
    public static function bindInstance(object $instance, string $typeString): object
    {
        static $phpDocParser = null;
        static $lexer = null;

        if ($phpDocParser === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        }

        try {
            $tokens = new TokenIterator($lexer->tokenize($typeString));
            $typeNode = $typeParser->parse($tokens);

            if ($typeNode instanceof GenericTypeNode) {
                self::bindInstanceFromNode($instance, $typeNode, forceBind: true);
            }
        } catch (\Throwable $e) {
            // Ignore malformed docblock strings
        }

        return $instance;
    }

    public static function checkParams(string $function, array $vars, ?object $thisObj = null): ?\TypeError
    {
        $contract = self::extractContract($function);
        if (! $contract || ! $contract['types']) {
            return null;
        }

        if (self::$registry === null) {
            self::$registry = new TypeValidatorRegistry();
        }

        if (self::$instanceTemplateBindings === null) {
            self::$instanceTemplateBindings = new \WeakMap();
        }

        $templates = $contract['templates'];
        $aliases = $contract['aliases'];
        $isInstanceMethod = $thisObj !== null;

        // Clear previous call bindings for functions/static methods to prevent pollution across calls
        if (! $isInstanceMethod) {
            foreach ($templates as $templateName => $_) {
                unset(self::$callTemplateBindings["{$function}:{$templateName}"]);
            }
        }

        foreach ($contract['types'] as $paramName => $typeNode) {
            if (! array_key_exists($paramName, $vars)) {
                continue;
            }

            // Resolve self, static, parent, $this
            $typeNode = self::resolveSpecialTypeNames($typeNode, $function, $thisObj);

            $val = $vars[$paramName];

            // Resolve Type Aliases (@phpstan-type / @psalm-type)
            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            // Resolve / Infer template types inside GenericTypeNode (e.g. class-string<T>)
            if ($typeNode instanceof GenericTypeNode && isset($typeNode->genericTypes[0]) && $typeNode->genericTypes[0] instanceof IdentifierTypeNode) {
                $baseType = strtolower($typeNode->type->name);
                $innerType = $typeNode->genericTypes[0];
                $templateName = $innerType->name;

                if ($baseType === 'class-string' && isset($templates[$templateName])) {
                    $templateNode = $templates[$templateName];
                    $alreadyBound = false;
                    $expectedTypeNode = null;

                    if ($isInstanceMethod) {
                        if (isset(self::$instanceTemplateBindings[$thisObj][$templateName])) {
                            $alreadyBound = true;
                            $expectedTypeNode = self::$instanceTemplateBindings[$thisObj][$templateName];
                        }
                    } else {
                        $callKey = "{$function}:{$templateName}";
                        if (isset(self::$callTemplateBindings[$callKey])) {
                            $alreadyBound = true;
                            $expectedTypeNode = self::$callTemplateBindings[$callKey];
                        }
                    }

                    if (! $alreadyBound) {
                        if (! is_string($val) || (! class_exists($val) && ! interface_exists($val) && ! trait_exists($val) && ! enum_exists($val))) {
                            return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($val) . ' given');
                        }

                        if ($templateNode->bound !== null) {
                            $boundName = $templateNode->bound instanceof IdentifierTypeNode ? $templateNode->bound->name : (string) $templateNode->bound;
                            if (! is_a($val, $boundName, true)) {
                                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundName . ", '$val' given");
                            }
                        }

                        $inferredType = new IdentifierTypeNode($val);

                        if ($isInstanceMethod) {
                            self::$instanceTemplateBindings[$thisObj][$templateName] = $inferredType;
                        } else {
                            self::$callTemplateBindings["{$function}:{$templateName}"] = $inferredType;
                        }
                    } else {
                        $targetClass = $expectedTypeNode instanceof IdentifierTypeNode ? $expectedTypeNode->name : (string) $expectedTypeNode;
                        if (! is_string($val) || ! is_a($val, $targetClass, true)) {
                            return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a class-string of ' . $targetClass . ", '$val' given");
                        }
                    }

                    continue;
                }
            }

            // Resolve / Infer direct template types (@template T)
            if ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name])) {
                $templateName = $typeNode->name;
                $templateNode = $templates[$templateName];

                $alreadyBound = false;
                $expectedTypeNode = null;

                if ($isInstanceMethod) {
                    if (! isset(self::$instanceTemplateBindings[$thisObj])) {
                        self::$instanceTemplateBindings[$thisObj] = [];
                    }
                    if (isset(self::$instanceTemplateBindings[$thisObj][$templateName])) {
                        $alreadyBound = true;
                        $expectedTypeNode = self::$instanceTemplateBindings[$thisObj][$templateName];
                    }
                } else {
                    $callKey = "{$function}:{$templateName}";
                    if (isset(self::$callTemplateBindings[$callKey])) {
                        $alreadyBound = true;
                        $expectedTypeNode = self::$callTemplateBindings[$callKey];
                    }
                }

                if (! $alreadyBound) {
                    $inferredType = self::inferTypeFromValue($val);

                    if ($templateNode->bound !== null) {
                        if ($err = self::$registry->validate($val, $templateNode->bound, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ')')) {
                            return $err;
                        }
                    }

                    if ($isInstanceMethod) {
                        self::$instanceTemplateBindings[$thisObj][$templateName] = $inferredType;
                    } else {
                        self::$callTemplateBindings["{$function}:{$templateName}"] = $inferredType;
                    }
                } else {
                    if ($err = self::$registry->validate($val, $expectedTypeNode, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ' = ' . $expectedTypeNode . ')')) {
                        return $err;
                    }
                }

                continue;
            }

            if ($err = self::$registry->validate($val, $typeNode, $function . '(): Argument $' . $paramName)) {
                return $err;
            }
        }

        return null;
    }

    public static function checkReturn(string $function, mixed $value, ?object $thisObj = null, array $vars = []): mixed
    {
        $contract = self::extractContract($function);
        $returnTypeNode = $contract['return'] ?? null;
        $aliases = $contract['aliases'] ?? [];

        if ($returnTypeNode === null) {
            return $value;
        }

        if (self::$registry === null) {
            self::$registry = new TypeValidatorRegistry();
        }

        if (self::$instanceTemplateBindings === null) {
            self::$instanceTemplateBindings = new \WeakMap();
        }

        // Check strict instance identity if return type is $this
        $isThisType = ($returnTypeNode instanceof ThisTypeNode)
            || ($returnTypeNode instanceof IdentifierTypeNode && strtolower($returnTypeNode->name) === '$this');

        if ($thisObj !== null && $isThisType) {
            if ($value !== $thisObj) {
                throw ErrorFactory::createError($function . '(): Return value must be $this instance, ' . TypeFormatter::formatGivenValue($value) . ' returned');
            }

            return $value;
        }

        // Resolve self, static, parent, $this
        $returnTypeNode = self::resolveSpecialTypeNames($returnTypeNode, $function, $thisObj);

        // Resolve Type Aliases (@phpstan-type / @psalm-type) for return type
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        // 1. Handle Parameter-based Conditional Return Types: ($input is string ? int : bool)
        if ($returnTypeNode instanceof ConditionalTypeForParameterNode) {
            $paramName = ltrim($returnTypeNode->parameterName, '$');
            $paramValue = $vars[$paramName] ?? null;

            $targetErr = self::$registry->validate($paramValue, $returnTypeNode->targetType, 'condition');
            $isTargetMatch = ($targetErr === null);

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $effectiveReturnTypeNode = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            if ($err = self::$registry->validate($value, $effectiveReturnTypeNode, $function . '(): Return value')) {
                throw $err;
            }

            return $value;
        }

        // 2. Handle Template-based Conditional Return Types: (T is string ? array : object)
        if ($returnTypeNode instanceof ConditionalTypeNode) {
            $subjectTypeNode = $returnTypeNode->subjectType;

            if ($subjectTypeNode instanceof IdentifierTypeNode) {
                $templateName = $subjectTypeNode->name;
                $isInstanceMethod = $thisObj !== null;

                if ($isInstanceMethod && isset(self::$instanceTemplateBindings[$thisObj][$templateName])) {
                    $subjectTypeNode = self::$instanceTemplateBindings[$thisObj][$templateName];
                } elseif (isset(self::$callTemplateBindings["{$function}:{$templateName}"])) {
                    $subjectTypeNode = self::$callTemplateBindings["{$function}:{$templateName}"];
                }
            }

            $subStr = (string) $subjectTypeNode;
            $targetStr = (string) $returnTypeNode->targetType;

            $isTargetMatch = ($subStr === $targetStr) ||
                ((class_exists($subStr) || interface_exists($subStr)) && (class_exists($targetStr) || interface_exists($targetStr)) && is_a($subStr, $targetStr, true));

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $effectiveReturnTypeNode = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            if ($err = self::$registry->validate($value, $effectiveReturnTypeNode, $function . '(): Return value')) {
                throw $err;
            }

            return $value;
        }

        $templates = $contract['templates'];
        $isInstanceMethod = $thisObj !== null;

        // Resolve / Check template return types (@return T)
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($templates[$returnTypeNode->name])) {
            $templateName = $returnTypeNode->name;
            $expectedTypeNode = null;

            if ($isInstanceMethod) {
                if (isset(self::$instanceTemplateBindings[$thisObj][$templateName])) {
                    $expectedTypeNode = self::$instanceTemplateBindings[$thisObj][$templateName];
                }
            } else {
                $callKey = "{$function}:{$templateName}";
                if (isset(self::$callTemplateBindings[$callKey])) {
                    $expectedTypeNode = self::$callTemplateBindings[$callKey];
                }
            }

            if ($expectedTypeNode !== null) {
                if ($err = self::$registry->validate($value, $expectedTypeNode, $function . '(): Return value (template ' . $templateName . ' = ' . $expectedTypeNode . ')')) {
                    throw $err;
                }
            }

            return $value;
        }

        if ($err = self::$registry->validate($value, $returnTypeNode, $function . '(): Return value')) {
            throw $err;
        }

        return $value;
    }

    /**
     * Wraps a callable/closure parameter in a runtime contract proxy that validates
     * arguments and return types when invoked.
     */
    public static function wrapCallable(string $function, string $paramName, mixed $callable): mixed
    {
        if (! is_callable($callable)) {
            return $callable;
        }

        $contract = self::extractContract($function);
        $typeNode = $contract['types'][$paramName] ?? null;
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        if (! ($typeNode instanceof \PHPStan\PhpDocParser\Ast\Type\CallableTypeNode)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));
        if ($identifierName === 'closure' && ! ($callable instanceof \Closure)) {
            $err = ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be an instance of Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given');

            throw $err;
        }

        if (self::$registry === null) {
            self::$registry = new TypeValidatorRegistry();
        }

        $registry = self::$registry;

        return function (...$args) use ($callable, $typeNode, $registry, $function, $paramName) {
            foreach ($typeNode->parameters as $index => $paramNode) {
                if (array_key_exists($index, $args)) {
                    if ($err = $registry->validate($args[$index], $paramNode->type, "$function(): Callback \$$paramName argument #" . ($index + 1))) {
                        throw $err;
                    }
                }
            }

            $result = $callable(...$args);

            if ($err = $registry->validate($result, $typeNode->returnType, "$function(): Callback \$$paramName return value")) {
                throw $err;
            }

            return $result;
        };
    }

    /**
     * Wraps a Traversable/Generator parameter in a lazy-validating generator wrapper
     * that validates items item-by-item as they are yielded.
     */
    public static function wrapIterable(string $function, string $paramName, mixed $iterable): mixed
    {
        if (! is_iterable($iterable) || is_array($iterable)) {
            return $iterable;
        }

        $contract = self::extractContract($function);
        $typeNode = $contract['types'][$paramName] ?? null;
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        if (self::$registry === null) {
            self::$registry = new TypeValidatorRegistry();
        }

        $registry = self::$registry;

        return (function () use ($iterable, $typeNode, $registry, $function, $paramName) {
            $itemTypeNode = null;
            $keyTypeNode = null;

            if ($typeNode instanceof GenericTypeNode) {
                $typesCount = count($typeNode->genericTypes);
                if ($typesCount === 1) {
                    $itemTypeNode = $typeNode->genericTypes[0];
                } elseif ($typesCount >= 2) {
                    $keyTypeNode = $typeNode->genericTypes[0];
                    $itemTypeNode = $typeNode->genericTypes[1];
                }
            } elseif ($typeNode instanceof ArrayTypeNode) {
                $itemTypeNode = $typeNode->type;
            }

            foreach ($iterable as $key => $value) {
                if ($keyTypeNode !== null) {
                    if ($err = $registry->validate($key, $keyTypeNode, "$function(): Iterator \$$paramName key")) {
                        throw $err;
                    }
                }
                if ($itemTypeNode !== null) {
                    if ($err = $registry->validate($value, $itemTypeNode, "$function(): Iterator \$$paramName value")) {
                        throw $err;
                    }
                }

                yield $key => $value;
            }
        })();
    }

    /**
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    private static function extractContract(string $function): array
    {
        if (isset(self::$contractCache[$function])) {
            return self::$contractCache[$function];
        }

        $classDoc = null;
        if (str_contains($function, '::')) {
            [$className, $methodName] = explode('::', $function, 2);
            $ref = new \ReflectionMethod($className, $methodName);
            $classDoc = $ref->getDeclaringClass()->getDocComment() ?: null;
        } else {
            $ref = new \ReflectionFunction($function);
        }

        $doc = $ref->getDocComment();
        if (! $doc && ! $classDoc) {
            return self::$contractCache[$function] = ['types' => [], 'templates' => [], 'return' => null, 'aliases' => []];
        }

        static $phpDocParser = null;
        static $lexer = null;

        if ($phpDocParser === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        }

        // Helper to get ALL template tags reliably
        $getAllTemplates = function ($node) {
            $tags = [];
            foreach ($node->getTags() as $tagNode) {
                if ($tagNode->value instanceof TemplateTagValueNode) {
                    $tags[] = $tagNode->value;
                }
            }

            return $tags;
        };

        try {
            $templates = [];
            $aliases = [];

            // 1. Extract Class-level templates and aliases
            if ($classDoc) {
                $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                $classPhpDocNode = $phpDocParser->parse($classTokens);

                foreach ($getAllTemplates($classPhpDocNode) as $templateTag) {
                    $templates[$templateTag->name] = $templateTag;
                }

                foreach ($classPhpDocNode->getTypeAliasTagValues() as $aliasTag) {
                    $aliases[$aliasTag->alias] = $aliasTag->type;
                }
            }

            // 2. Extract Method/Function-level templates, params, return, and aliases
            $types = [];
            $returnType = null;
            if ($doc) {
                $tokens = new TokenIterator($lexer->tokenize($doc));
                $phpDocNode = $phpDocParser->parse($tokens);

                foreach ($getAllTemplates($phpDocNode) as $templateTag) {
                    $templates[$templateTag->name] = $templateTag;
                }

                foreach ($phpDocNode->getTypeAliasTagValues() as $aliasTag) {
                    $aliases[$aliasTag->alias] = $aliasTag->type;
                }

                $refParams = [];
                foreach ($ref->getParameters() as $p) {
                    $refParams[$p->getName()] = $p->isVariadic();
                }

                foreach ($phpDocNode->getParamTagValues() as $paramTag) {
                    $paramName = ltrim($paramTag->parameterName, '$');
                    $type = $paramTag->type;

                    // Automatically wrap variadic parameters into an ArrayTypeNode (Type[])
                    $isVariadic = $paramTag->isVariadic || ($refParams[$paramName] ?? false);
                    if ($isVariadic) {
                        $type = new ArrayTypeNode($type);
                    }

                    $types[$paramName] = $type;
                }

                $returnTags = $phpDocNode->getReturnTagValues();
                if (! empty($returnTags)) {
                    $returnType = $returnTags[0]->type;
                }
            }

            return self::$contractCache[$function] = [
                'types' => $types,
                'templates' => $templates,
                'return' => $returnType,
                'aliases' => $aliases,
            ];
        } catch (\Throwable $e) {
            return self::$contractCache[$function] = ['types' => [], 'templates' => [], 'return' => null, 'aliases' => []];
        }
    }

    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        if (is_int($value)) {
            return new IdentifierTypeNode('int');
        }
        if (is_string($value)) {
            return new IdentifierTypeNode('string');
        }
        if (is_float($value)) {
            return new IdentifierTypeNode('float');
        }
        if (is_bool($value)) {
            return new IdentifierTypeNode('bool');
        }
        if (is_array($value)) {
            return new IdentifierTypeNode(array_is_list($value) ? 'list' : 'array');
        }
        if (is_object($value)) {
            $className = get_class($value);

            // If the object has bound generic templates (e.g. Producer<Dog>), preserve them!
            if (self::$instanceTemplateBindings !== null && isset(self::$instanceTemplateBindings[$value]) && ! empty(self::$instanceTemplateBindings[$value])) {
                $genericTypes = array_values(self::$instanceTemplateBindings[$value]);

                return new GenericTypeNode(new IdentifierTypeNode($className), $genericTypes);
            }

            return new IdentifierTypeNode($className);
        }
        if ($value === null) {
            return new IdentifierTypeNode('null');
        }

        return new IdentifierTypeNode('mixed');
    }
}
