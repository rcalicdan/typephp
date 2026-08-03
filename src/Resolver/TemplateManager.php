<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

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
use TypePHP\Internal\ErrorFactory;

final class TemplateManager
{
    /**
     * @var \WeakMap<object, array<string, TypeNode>>|null
     */
    private static ?\WeakMap $instanceTemplateBindings = null;

    /**
     * @var array<string, TypeNode>
     */
    private static array $callTemplateBindings = [];

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    public static function clearCallBindings(string $function, array $templates): void
    {
        foreach ($templates as $templateName => $_) {
            unset(self::$callTemplateBindings["{$function}:{$templateName}"]);
        }
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     * @return array<string, TypeNode>
     */
    public static function getBoundTemplates(string $function, ?object $thisObj, array $templates): array
    {
        $bound = [];
        if ($thisObj !== null && isset(self::$instanceTemplateBindings[$thisObj])) {
            return self::$instanceTemplateBindings[$thisObj];
        }

        foreach ($templates as $templateName => $_) {
            $callKey = "{$function}:{$templateName}";
            if (isset(self::$callTemplateBindings[$callKey])) {
                $bound[$templateName] = self::$callTemplateBindings[$callKey];
            }
        }

        return $bound;
    }

    public static function isBound(string $function, ?object $thisObj, string $templateName): bool
    {
        if ($thisObj !== null) {
            return isset(self::$instanceTemplateBindings[$thisObj][$templateName]);
        }

        return isset(self::$callTemplateBindings["{$function}:{$templateName}"]);
    }

    public static function getBoundType(string $function, ?object $thisObj, string $templateName): ?TypeNode
    {
        if ($thisObj !== null) {
            return self::$instanceTemplateBindings[$thisObj][$templateName] ?? null;
        }

        return self::$callTemplateBindings["{$function}:{$templateName}"] ?? null;
    }

    public static function bindTemplate(string $function, ?object $thisObj, string $templateName, TypeNode $inferredType): void
    {
        if ($thisObj !== null) {
            if (self::$instanceTemplateBindings === null) {
                self::$instanceTemplateBindings = new \WeakMap();
            }
            $bindings = self::$instanceTemplateBindings[$thisObj] ?? [];
            $bindings[$templateName] = $inferredType;
            self::$instanceTemplateBindings[$thisObj] = $bindings;
        } else {
            self::$callTemplateBindings["{$function}:{$templateName}"] = $inferredType;
        }
    }

    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?\TypeError
    {
        $className = $typeNode->type->name;
        if (in_array(strtolower($className), ['self', 'static', '$this'], true)) {
            $className = get_class($instance);
        }

        if (! is_a($instance, $className)) {
            return null;
        }

        if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
            return null;
        }

        self::resolveInheritedTemplates($instance, $className);

        try {
            $ref = new \ReflectionClass($className);
            $classDoc = $ref->getDocComment();

            if ($classDoc !== false) {
                /** @var PhpDocParser|null $phpDocParser */
                static $phpDocParser = null;
                /** @var Lexer|null $lexer */
                static $lexer = null;

                if ($phpDocParser === null || $lexer === null) {
                    $config = new ParserConfig(usedAttributes: []);
                    $lexer = new Lexer($config);
                    $constExprParser = new ConstExprParser($config);
                    $typeParser = new TypeParser($config, $constExprParser);
                    $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
                }

                $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                $classPhpDocNode = $phpDocParser->parse($classTokens);

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

                        $variance = ($usageVariance !== GenericTypeNode::VARIANCE_INVARIANT)
                            ? $usageVariance
                            : $declaredVariance;

                        $templateName = $templateTag->name;

                        if ($forceBind) {
                            $bindings = self::$instanceTemplateBindings[$instance] ?? [];
                            $bindings[$templateName] = $expectedTypeNode;
                            self::$instanceTemplateBindings[$instance] = $bindings;
                        } else {
                            $existingTypeNode = self::$instanceTemplateBindings[$instance][$templateName]
                                ?? new IdentifierTypeNode('mixed');

                            $valid = self::checkVariance($existingTypeNode, $expectedTypeNode, $variance);

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

    private static function resolveInheritedTemplates(object $instance, string $targetClassName): void
    {
        $actualClassName = get_class($instance);

        try {
            $ref = new \ReflectionClass($actualClassName);
            $classDoc = $ref->getDocComment();

            if ($classDoc !== false) {
                /** @var PhpDocParser|null $phpDocParser */
                static $phpDocParser = null;
                /** @var Lexer|null $lexer */
                static $lexer = null;

                if ($phpDocParser === null || $lexer === null) {
                    $config = new ParserConfig(usedAttributes: []);
                    $lexer = new Lexer($config);
                    $constExprParser = new ConstExprParser($config);
                    $typeParser = new TypeParser($config, $constExprParser);
                    $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
                }

                $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                $classPhpDocNode = $phpDocParser->parse($classTokens);

                $inheritedTags = array_merge(
                    $classPhpDocNode->getExtendsTagValues(),
                    $classPhpDocNode->getImplementsTagValues()
                );

                foreach ($inheritedTags as $inheritedTag) {
                    $genericTypeNode = $inheritedTag->type;
                    if ($genericTypeNode instanceof GenericTypeNode) {
                        $parentName = $genericTypeNode->type->name;

                        if ($parentName === $targetClassName || is_a($parentName, $targetClassName, true)) {
                            if (! class_exists($parentName) && ! interface_exists($parentName)) {
                                continue;
                            }

                            $parentRef = new \ReflectionClass($parentName);
                            $parentDoc = $parentRef->getDocComment();

                            if ($parentDoc !== false) {
                                $parentTokens = new TokenIterator($lexer->tokenize($parentDoc));
                                $parentPhpDocNode = $phpDocParser->parse($parentTokens);

                                $parentTemplateNames = [];
                                foreach ($parentPhpDocNode->getTags() as $tag) {
                                    if ($tag->value instanceof TemplateTagValueNode) {
                                        $parentTemplateNames[] = $tag->value->name;
                                    }
                                }

                                $bindings = self::$instanceTemplateBindings[$instance] ?? [];
                                foreach ($parentTemplateNames as $idx => $templateName) {
                                    if (isset($genericTypeNode->genericTypes[$idx])) {
                                        $bindings[$templateName] = $genericTypeNode->genericTypes[$idx];
                                    }
                                }

                                if (self::$instanceTemplateBindings === null) {
                                    self::$instanceTemplateBindings = new \WeakMap();
                                }
                                self::$instanceTemplateBindings[$instance] = $bindings;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    public static function checkVariance(TypeNode $existing, TypeNode $expected, string $variance): bool
    {
        $existingStr = (string) $existing;
        $expectedStr = (string) $expected;

        if ($existingStr === $expectedStr) {
            return true;
        }

        if ($variance === GenericTypeNode::VARIANCE_BIVARIANT || $expectedStr === 'mixed') {
            return true;
        }

        // Unroll and recursively check nested generic type nodes
        if ($existing instanceof GenericTypeNode && $expected instanceof GenericTypeNode) {
            if (! is_a($existing->type->name, $expected->type->name, true)) {
                return false;
            }

            foreach ($expected->genericTypes as $idx => $expectedInner) {
                $existingInner = $existing->genericTypes[$idx] ?? new IdentifierTypeNode('mixed');
                $innerVariance = $expected->variances[$idx] ?? GenericTypeNode::VARIANCE_INVARIANT;

                if (! self::checkVariance($existingInner, $expectedInner, $innerVariance)) {
                    return false;
                }
            }

            return true;
        }

        $isSubclass = function (string $sub, string $super): bool {
            if ((class_exists($sub) || interface_exists($sub)) && (class_exists($super) || interface_exists($super))) {
                return is_a($sub, $super, true);
            }

            return false;
        };

        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            return $isSubclass($existingStr, $expectedStr);
        }

        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            return $isSubclass($expectedStr, $existingStr);
        }

        return false;
    }

    public static function bindInstance(object $instance, string $typeString): object
    {
        /** @var TypeParser|null $typeParser */
        static $typeParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($typeParser === null || $lexer === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
        }

        try {
            $tokens = new TokenIterator($lexer->tokenize($typeString));
            $typeNode = $typeParser->parse($tokens);

            if ($typeNode instanceof GenericTypeNode) {
                self::bindInstanceFromNode($instance, $typeNode, '', true);
            }
        } catch (\Throwable $e) {
            // Ignore malformed docblock strings
        }

        return $instance;
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
            if (self::$instanceTemplateBindings !== null && isset(self::$instanceTemplateBindings[$value]) && count(self::$instanceTemplateBindings[$value]) > 0) {
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