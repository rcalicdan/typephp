<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Config;
use TypePHP\Resolver\SpecialTypeResolver;

/**
 * @internal Main orchestrator parsing and caching PHPDoc contracts (@param, @return, @template, @phpstan-type, @var).
 */
final class ContractParser
{
    /**
     * Cache for resolved contract metadata.
     *
     * @var array<string, array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}>
     */
    private static array $cache = [];

    /**
     * Cache for resolved class property types.
     *
     * @var array<string, ?TypeNode>
     */
    private static array $propertyCache = [];

    /**
     * Parses PHPDoc contracts for a function or class method.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    public static function parse(string $function): array
    {
        if (isset(self::$cache[$function])) {
            return self::$cache[$function];
        }

        try {
            if (str_contains($function, '::')) {
                [$className, $methodName] = explode('::', $function, 2);
                $ref = new \ReflectionMethod($className, $methodName);
                $contract = self::parseMethod($ref);
            } else {
                $ref = new \ReflectionFunction($function);
                $contract = self::parseFunction($ref);
            }
        } catch (\ReflectionException $e) {
            $contract = ['types' => [], 'templates' => [], 'return' => null, 'aliases' => []];
        }

        return self::$cache[$function] = $contract;
    }

    /**
     * Parses and resolves the @var docblock for a given class property (including PHP 8.4 interface properties).
     */
    public static function parseProperty(string $className, string $propertyName): ?TypeNode
    {
        $cacheKey = $className . '::$' . $propertyName;
        if (\array_key_exists($cacheKey, self::$propertyCache)) {
            return self::$propertyCache[$cacheKey];
        }

        if (! class_exists($className) && ! trait_exists($className) && ! interface_exists($className)) {
            return self::$propertyCache[$cacheKey] = null;
        }

        try {
            $refClass = new \ReflectionClass($className);

            $doc = false;
            $declaringClass = null;

            // 1. Search Class and Parent Class Hierarchy
            $current = $refClass;
            while ($current !== false) {
                if ($current->hasProperty($propertyName)) {
                    $refProp = $current->getProperty($propertyName);
                    $fetchedDoc = $refProp->getDocComment();
                    if ($fetchedDoc !== false) {
                        $doc = $fetchedDoc;
                        $declaringClass = $current;

                        break;
                    }
                }
                $current = $current->getParentClass();
            }

            // 2. Search Implemented Interfaces (PHP 8.4 Interface Properties)
            if ($doc === false) {
                foreach ($refClass->getInterfaces() as $interface) {
                    if ($interface->hasProperty($propertyName)) {
                        $interfaceProp = $interface->getProperty($propertyName);
                        $fetchedDoc = $interfaceProp->getDocComment();
                        if ($fetchedDoc !== false) {
                            $doc = $fetchedDoc;
                            $declaringClass = $interface;

                            break;
                        }
                    }
                }
            }

            if ($doc === false || $declaringClass === null) {
                return self::$propertyCache[$cacheKey] = null;
            }

            // Skip property type checks if docblock contains @typephp-ignore
            $shouldRespectIgnore = (bool) (Config::get()['respect_ignore_tags'] ?? true);
            if ($shouldRespectIgnore && (str_contains($doc, '@typephp-ignore') || str_contains($doc, '@typephp-disable'))) {
                return self::$propertyCache[$cacheKey] = null;
            }

            $phpDocNode = DocblockExtractor::parseDocString($doc);
            $varTags = $phpDocNode->getVarTagValues();

            if (\count($varTags) === 0) {
                return self::$propertyCache[$cacheKey] = null;
            }

            $typeNode = $varTags[0]->type;
            $aliases = [];
            DocblockExtractor::extractAliases($phpDocNode, $aliases, $declaringClass);

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            $resolvedNode = SpecialTypeResolver::resolve($typeNode, $declaringClass);

            return self::$propertyCache[$cacheKey] = $resolvedNode;
        } catch (\Throwable $e) {
            return self::$propertyCache[$cacheKey] = null;
        }
    }

    /**
     * Orchestrates parsing for class methods across the inheritance hierarchy.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    private static function parseMethod(\ReflectionMethod $ref): array
    {
        $types = [];
        $templates = [];
        $returnType = null;
        $aliases = [];

        self::parseClassLevelDocs($ref->getDeclaringClass(), $templates, $aliases);
        self::parseMethodHierarchyDocs($ref, $types, $templates, $returnType, $aliases);

        if ($ref->getName() === '__construct') {
            self::applyConstructorPromotionFallback($ref, $types);
        }

        return [
            'types' => $types,
            'templates' => $templates,
            'return' => $returnType,
            'aliases' => $aliases,
        ];
    }

    /**
     * Orchestrates parsing for standalone global or namespaced functions.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    private static function parseFunction(\ReflectionFunction $ref): array
    {
        $types = [];
        $templates = [];
        $returnType = null;
        $aliases = [];

        $doc = $ref->getDocComment();
        if ($doc === false) {
            return [
                'types' => [],
                'templates' => [],
                'return' => null,
                'aliases' => [],
            ];
        }

        $phpDocNode = DocblockExtractor::parseDocString($doc);

        foreach (DocblockExtractor::extractTemplates($phpDocNode) as $name => $tag) {
            $templates[$name] = $tag;
        }
        DocblockExtractor::extractAliases($phpDocNode, $aliases, $ref);

        $baseParams = $ref->getParameters();
        $baseParamVariadic = [];
        foreach ($baseParams as $p) {
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
        }

        foreach ($phpDocNode->getParamTagValues() as $paramTag) {
            $paramName = ltrim($paramTag->parameterName, '$');
            $type = $paramTag->type;
            $isVariadic = $paramTag->isVariadic || ($baseParamVariadic[$paramName] ?? false);
            if ($isVariadic) {
                $type = new ArrayTypeNode($type);
            }
            $types[$paramName] = SpecialTypeResolver::resolve($type, $ref);
        }

        $returnTags = $phpDocNode->getReturnTagValues();
        if (\count($returnTags) > 0) {
            $returnType = SpecialTypeResolver::resolve($returnTags[0]->type, $ref);
        }

        return [
            'types' => $types,
            'templates' => $templates,
            'return' => $returnType,
            'aliases' => $aliases,
        ];
    }

    /**
     * Resolves class-level docblocks (templates and aliases) up the class inheritance chain.
     *
     * @param \ReflectionClass<object> $declaringClass
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TypeNode> $aliases
     */
    private static function parseClassLevelDocs(\ReflectionClass $declaringClass, array &$templates, array &$aliases): void
    {
        $classHierarchy = HierarchyResolver::getClassHierarchy($declaringClass);

        foreach ($classHierarchy as $hierClass) {
            $fileName = $hierClass->getFileName();
            if (FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                continue;
            }

            $classDoc = $hierClass->getDocComment();
            if ($classDoc !== false) {
                $classPhpDocNode = DocblockExtractor::parseDocString($classDoc);

                foreach (DocblockExtractor::extractTemplates($classPhpDocNode) as $name => $tag) {
                    if (! isset($templates[$name])) {
                        $templates[$name] = $tag;
                    }
                }
                DocblockExtractor::extractAliases($classPhpDocNode, $aliases, $hierClass);
            }
        }
    }

    /**
     * Resolves method-level docblocks (@param, @return, @template, aliases) up the method hierarchy.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TypeNode> $aliases
     */
    private static function parseMethodHierarchyDocs(
        \ReflectionMethod $ref,
        array &$types,
        array &$templates,
        ?TypeNode &$returnType,
        array &$aliases
    ): void {
        $hierarchy = HierarchyResolver::getMethodHierarchy($ref);
        $baseParams = $ref->getParameters();
        $baseParamNames = [];
        $baseParamVariadic = [];

        foreach ($baseParams as $idx => $p) {
            $baseParamNames[$idx] = $p->getName();
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
        }

        foreach ($hierarchy as $hierRef) {
            $isOriginal = ($hierRef === $ref);

            $fileName = $hierRef->getFileName();
            if (! $isOriginal && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                continue;
            }

            $doc = $hierRef->getDocComment();
            if ($doc === false) {
                continue;
            }

            $phpDocNode = DocblockExtractor::parseDocString($doc);

            foreach (DocblockExtractor::extractTemplates($phpDocNode) as $name => $tag) {
                if (! isset($templates[$name])) {
                    $templates[$name] = $tag;
                }
            }
            DocblockExtractor::extractAliases($phpDocNode, $aliases, $hierRef);

            $hierParams = $hierRef->getParameters();
            $hierNameToIndex = [];
            foreach ($hierParams as $idx => $p) {
                $hierNameToIndex[$p->getName()] = $idx;
            }

            foreach ($phpDocNode->getParamTagValues() as $paramTag) {
                $paramName = ltrim($paramTag->parameterName, '$');
                $paramIndex = $hierNameToIndex[$paramName] ?? null;

                if ($paramIndex !== null && isset($baseParamNames[$paramIndex])) {
                    $baseParamName = $baseParamNames[$paramIndex];

                    if (! isset($types[$baseParamName])) {
                        $type = $paramTag->type;
                        $isVariadic = $paramTag->isVariadic || $baseParamVariadic[$baseParamName];
                        if ($isVariadic) {
                            $type = new ArrayTypeNode($type);
                        }
                        $types[$baseParamName] = SpecialTypeResolver::resolve($type, $hierRef);
                    }
                }
            }

            if ($returnType === null) {
                $returnTags = $phpDocNode->getReturnTagValues();
                if (\count($returnTags) > 0) {
                    $returnType = SpecialTypeResolver::resolve($returnTags[0]->type, $hierRef);
                }
            }
        }
    }

    /**
     * Falls back to property @var docblocks for constructor promoted parameters if un-annotated.
     *
     * @param array<string, TypeNode> $types
     */
    private static function applyConstructorPromotionFallback(\ReflectionMethod $ref, array &$types): void
    {
        $declaringClass = $ref->getDeclaringClass();

        foreach ($ref->getParameters() as $p) {
            $paramName = $p->getName();

            if (! isset($types[$paramName]) && $declaringClass->hasProperty($paramName)) {
                $propertyRef = $declaringClass->getProperty($paramName);
                $propDoc = $propertyRef->getDocComment();

                if ($propDoc !== false) {
                    $propType = DocblockExtractor::extractTypeFromPropertyDoc($propDoc, $paramName);
                    if ($propType !== null) {
                        $isVariadic = $p->isVariadic();
                        if ($isVariadic) {
                            $propType = new ArrayTypeNode($propType);
                        }
                        $types[$paramName] = SpecialTypeResolver::resolve($propType, $ref);
                    }
                }
            }
        }
    }
}
