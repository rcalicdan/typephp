<?php

declare(strict_types=1);

namespace TypePHP;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
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
     * Binds or validates instance template types using a GenericTypeNode (e.g. Collection<int>).
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = ''): ?\TypeError
    {
        $className = $typeNode->type->name;
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
                if (!isset(self::$instanceTemplateBindings[$instance])) {
                    self::$instanceTemplateBindings[$instance] = [];
                }

                foreach ($templates as $index => $templateTag) {
                    if (isset($typeNode->genericTypes[$index])) {
                        $expectedTypeNode = $typeNode->genericTypes[$index];

                        // Class declared variance takes precedence, fallback to usage site variance
                        $variance = $classVariances[$templateTag->name]
                            ?? $typeNode->variances[$index]
                            ?? GenericTypeNode::VARIANCE_INVARIANT;

                        $templateName = $templateTag->name;

                        if (isset(self::$instanceTemplateBindings[$instance][$templateName])) {
                            $existingTypeNode = self::$instanceTemplateBindings[$instance][$templateName];

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
                        } else {
                            self::$instanceTemplateBindings[$instance][$templateName] = $expectedTypeNode;
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
        $existingStr = (string)$existing;
        $expectedStr = (string)$expected;

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
                self::bindInstanceFromNode($instance, $typeNode);
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
        if (!$isInstanceMethod) {
            foreach ($templates as $templateName => $_) {
                unset(self::$callTemplateBindings["{$function}:{$templateName}"]);
            }
        }

        foreach ($contract['types'] as $paramName => $typeNode) {
            if (! array_key_exists($paramName, $vars)) {
                continue;
            }

            $val = $vars[$paramName];

            // Resolve Type Aliases (@phpstan-type / @psalm-type)
            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            // Resolve / Infer template types (@template T)
            if ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name])) {
                $templateName = $typeNode->name;
                $templateNode = $templates[$templateName];

                $alreadyBound = false;
                $expectedTypeNode = null;

                if ($isInstanceMethod) {
                    if (!isset(self::$instanceTemplateBindings[$thisObj])) {
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
                    // First time seeing template T for this instance/call -> Infer type from $val
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
                    // Template T was already inferred or pre-bound -> Enforce bound type
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

    public static function checkReturn(string $function, mixed $value, ?object $thisObj = null): mixed
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

        // Resolve Type Aliases (@phpstan-type / @psalm-type) for return type
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
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

        // Resolve Type Aliases (@phpstan-type) for callables
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
            } elseif ($typeNode instanceof \PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode) {
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

                foreach ($phpDocNode->getParamTagValues() as $paramTag) {
                    $paramName = ltrim($paramTag->parameterName, '$');
                    $types[$paramName] = $paramTag->type;
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
            return new IdentifierTypeNode(get_class($value));
        }
        if ($value === null) {
            return new IdentifierTypeNode('null');
        }

        return new IdentifierTypeNode('mixed');
    }
}
