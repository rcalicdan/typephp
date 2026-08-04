<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\Config;
use TypePHP\Internal\DocblockNormalizer;
use TypePHP\Resolver\SpecialTypeResolver;

/**
 * Parses and caches PHPDoc contracts (@param, @return, @template, @phpstan-type, @var) for functions, methods, and properties.
 */
final class ContractParser
{
    /**
     * Cache for resolved contract metadata keyed by function or method name.
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
     * Parses PHPDoc contracts for a given function or method name by recursively merging inheritance gaps.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    public static function parse(string $function): array
    {
        if (isset(self::$cache[$function])) {
            return self::$cache[$function];
        }

        $isMethod = str_contains($function, '::');

        try {
            if ($isMethod) {
                [$className, $methodName] = explode('::', $function, 2);
                $ref = new \ReflectionMethod($className, $methodName);
            } else {
                $ref = new \ReflectionFunction($function);
            }
        } catch (\ReflectionException $e) {
            return self::$cache[$function] = ['types' => [], 'templates' => [], 'return' => null, 'aliases' => []];
        }

        $types = [];
        $templates = [];
        $returnType = null;
        $aliases = [];

        [$phpDocParser, $lexer] = self::getParserComponents();

        // 1. Resolve Class-Level Docs (Templates & Aliases) merging up the inheritance chain
        if ($isMethod) {
            $classHierarchy = self::getClassHierarchy($ref->getDeclaringClass());
            foreach ($classHierarchy as $hierClass) {
                if (self::isFileExcluded($hierClass->getFileName())) {
                    continue;
                }

                $classDoc = $hierClass->getDocComment();
                if ($classDoc !== false) {
                    $classDoc = DocblockNormalizer::normalize($classDoc);
                    $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                    $classPhpDocNode = $phpDocParser->parse($classTokens);

                    foreach (self::extractTemplates($classPhpDocNode) as $name => $tag) {
                        if (! isset($templates[$name])) {
                            $templates[$name] = $tag;
                        }
                    }
                    self::extractAliases($classPhpDocNode, $aliases, $hierClass);
                }
            }
        }

        // 2. Resolve Method/Function Docs merging up the inheritance chain (Resolves Liskov Substitution Gaps)
        $baseParams = $ref->getParameters();
        $baseParamNames = [];
        $baseParamVariadic = [];
        foreach ($baseParams as $idx => $p) {
            $baseParamNames[$idx] = $p->getName();
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
        }

        if ($isMethod) {
            $hierarchy = self::getMethodHierarchy($ref);

            foreach ($hierarchy as $hierRef) {
                $isOriginal = ($hierRef === $ref);
                
                // Prevent vendor docblock bleed for inherited methods!
                if (! $isOriginal && self::isFileExcluded($hierRef->getFileName())) {
                    continue;
                }

                $doc = $hierRef->getDocComment();
                if ($doc !== false) {
                    $doc = DocblockNormalizer::normalize($doc);
                    $tokens = new TokenIterator($lexer->tokenize($doc));
                    $phpDocNode = $phpDocParser->parse($tokens);

                    foreach (self::extractTemplates($phpDocNode) as $name => $tag) {
                        if (! isset($templates[$name])) {
                            $templates[$name] = $tag;
                        }
                    }
                    self::extractAliases($phpDocNode, $aliases, $hierRef);

                    // Map inherited parameters by INDEX to survive parameter renaming in child classes
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
                            
                            // Fill the gap ONLY if the child hasn't already defined a type for this parameter
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

                    // Fill return type gap if child omitted it
                    if ($returnType === null) {
                        $returnTags = $phpDocNode->getReturnTagValues();
                        if (count($returnTags) > 0) {
                            $returnType = SpecialTypeResolver::resolve($returnTags[0]->type, $hierRef);
                        }
                    }
                }
            }

            // Fallback to Constructor Property Promotion (@var)
            if ($ref->getName() === '__construct') {
                $declaringClass = $ref->getDeclaringClass();
                foreach ($baseParams as $p) {
                    $paramName = $p->getName();
                    if (! isset($types[$paramName]) && $declaringClass->hasProperty($paramName)) {
                        $propertyRef = $declaringClass->getProperty($paramName);
                        $propDoc = $propertyRef->getDocComment();
                        
                        if ($propDoc !== false) {
                            $propType = self::extractTypeFromPropertyDoc($propDoc, $paramName);
                            if ($propType !== null) {
                                $isVariadic = $baseParamVariadic[$paramName] ?? false;
                                if ($isVariadic) {
                                    $propType = new ArrayTypeNode($propType);
                                }
                                $types[$paramName] = SpecialTypeResolver::resolve($propType, $ref);
                            }
                        }
                    }
                }
            }

        } else {
            // Normal Function Parsing (No inheritance possible)
            $doc = $ref->getDocComment();
            if ($doc !== false) {
                $doc = DocblockNormalizer::normalize($doc);
                $tokens = new TokenIterator($lexer->tokenize($doc));
                $phpDocNode = $phpDocParser->parse($tokens);

                foreach (self::extractTemplates($phpDocNode) as $name => $tag) {
                    $templates[$name] = $tag;
                }
                self::extractAliases($phpDocNode, $aliases, $ref);

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
                if (count($returnTags) > 0) {
                    $returnType = SpecialTypeResolver::resolve($returnTags[0]->type, $ref);
                }
            }
        }

        return self::$cache[$function] = [
            'types' => $types,
            'templates' => $templates,
            'return' => $returnType,
            'aliases' => $aliases,
        ];
    }

    /**
     * Parses and resolves the @var docblock for a given class property.
     */
    public static function parseProperty(string $className, string $propertyName): ?TypeNode
    {
        $cacheKey = $className . '::$' . $propertyName;
        if (array_key_exists($cacheKey, self::$propertyCache)) {
            return self::$propertyCache[$cacheKey];
        }

        if (! class_exists($className) && ! trait_exists($className)) {
            return self::$propertyCache[$cacheKey] = null;
        }

        try {
            $refClass = new \ReflectionClass($className);

            $declaringClass = null;
            $current = $refClass;
            while ($current !== false) {
                if ($current->hasProperty($propertyName)) {
                    $declaringClass = $current;
                    break;
                }
                $current = $current->getParentClass();
            }

            if ($declaringClass === null) {
                return self::$propertyCache[$cacheKey] = null;
            }

            $refProp = $declaringClass->getProperty($propertyName);
            $doc = $refProp->getDocComment();

            if ($doc === false) {
                return self::$propertyCache[$cacheKey] = null;
            }

            $doc = DocblockNormalizer::normalize($doc);
            [$phpDocParser, $lexer] = self::getParserComponents();

            $tokens = new TokenIterator($lexer->tokenize($doc));
            $phpDocNode = $phpDocParser->parse($tokens);

            $varTags = $phpDocNode->getVarTagValues();
            if (count($varTags) === 0) {
                return self::$propertyCache[$cacheKey] = null;
            }

            $typeNode = $varTags[0]->type;

            $aliases = [];
            self::extractAliases($phpDocNode, $aliases, $declaringClass);

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
     * Builds an array of ReflectionMethods representing the inheritance hierarchy from child to root.
     *
     * @return array<int, \ReflectionMethod>
     */
    private static function getMethodHierarchy(\ReflectionMethod $ref): array
    {
        $hierarchy = [$ref];
        $methodName = $ref->getName();
        $declaringClass = $ref->getDeclaringClass();

        $parent = $declaringClass->getParentClass();
        while ($parent !== false) {
            if ($parent->hasMethod($methodName)) {
                $hierarchy[] = $parent->getMethod($methodName);
            }
            $parent = $parent->getParentClass();
        }

        foreach ($declaringClass->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                $hierarchy[] = $interface->getMethod($methodName);
            }
        }

        foreach ($declaringClass->getTraits() as $trait) {
            if ($trait->hasMethod($methodName)) {
                $hierarchy[] = $trait->getMethod($methodName);
            }
        }

        return $hierarchy;
    }

    /**
     * Builds an array of ReflectionClasses representing the inheritance hierarchy from child to root.
     *
     * @return array<int, \ReflectionClass>
     */
    private static function getClassHierarchy(\ReflectionClass $ref): array
    {
        $hierarchy = [$ref];
        
        $parent = $ref->getParentClass();
        while ($parent !== false) {
            $hierarchy[] = $parent;
            $parent = $parent->getParentClass();
        }

        foreach ($ref->getInterfaces() as $interface) {
            $hierarchy[] = $interface;
        }

        foreach ($ref->getTraits() as $trait) {
            $hierarchy[] = $trait;
        }

        return $hierarchy;
    }

    /**
     * Prevents vendor bleed by checking if the inherited class/interface file matches Exclude config.
     */
    private static function isFileExcluded(?string $fileName): bool
    {
        if ($fileName === null || $fileName === false || $fileName === '') {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $fileName);
        
        // Fast hardcoded vendor check to protect against bleed even if config is missing
        if (str_contains($normalizedPath, '/vendor/')) {
            return true;
        }

        $config = Config::get();
        $excludes = is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];
        
        $cwd = getcwd();
        $baseDir = $cwd !== false ? rtrim(str_replace('\\', '/', $cwd), '/') : '';

        foreach ($excludes as $pattern) {
            $glob = str_replace('\\', '/', trim($pattern));
            $isAbsolute = str_starts_with($glob, '/') || preg_match('#^[a-zA-Z]:/#', $glob);

            $regex = preg_quote($glob, '#');
            $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);

            if ($isAbsolute) {
                $regexPattern = '^' . $regex . '$';
            } elseif (str_starts_with($glob, '**')) {
                $regexPattern = '.*' . substr($regex, 4) . '$';
            } else {
                $regexPattern = '^' . preg_quote($baseDir . '/', '#') . $regex . '$';
            }

            if (preg_match('#' . $regexPattern . '#i', $normalizedPath) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns shared static instances of PHPStan's PhpDocParser and Lexer.
     *
     * @return array{PhpDocParser, Lexer}
     */
    private static function getParserComponents(): array
    {
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

        return [$phpDocParser, $lexer];
    }

    /**
     * Extracts all @template tag values from a parsed PHPDoc node.
     *
     * @return array<string, TemplateTagValueNode>
     */
    private static function extractTemplates(PhpDocNode $node): array
    {
        $tags = [];
        foreach ($node->getTags() as $tagNode) {
            if ($tagNode->value instanceof TemplateTagValueNode) {
                $tags[$tagNode->value->name] = $tagNode->value;
            }
        }

        return $tags;
    }

    /**
     * Extracts a TypeNode from a property's @var or @param docblock.
     */
    private static function extractTypeFromPropertyDoc(string $doc, string $propName): ?TypeNode
    {
        try {
            $doc = DocblockNormalizer::normalize($doc);
            [$phpDocParser, $lexer] = self::getParserComponents();

            $tokens = new TokenIterator($lexer->tokenize($doc));
            $phpDocNode = $phpDocParser->parse($tokens);

            foreach ($phpDocNode->getVarTagValues() as $varTag) {
                $tagVarName = ltrim($varTag->variableName, '$');
                if ($tagVarName === '' || $tagVarName === $propName) {
                    return $varTag->type;
                }
            }

            foreach ($phpDocNode->getParamTagValues() as $paramTag) {
                $tagParamName = ltrim($paramTag->parameterName, '$');
                if ($tagParamName === '' || $tagParamName === $propName) {
                    return $paramTag->type;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed property docblocks
        }

        return null;
    }

    /**
     * Extracts local and imported type aliases (@phpstan-type and @phpstan-import-type) from a PHPDoc node.
     *
     * @param array<string, TypeNode> $aliases
     */
    private static function extractAliases(
        PhpDocNode $phpDocNode,
        array &$aliases,
        \ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref
    ): void {
        foreach ($phpDocNode->getTypeAliasTagValues() as $aliasTag) {
            $aliases[$aliasTag->alias] = $aliasTag->type;
        }

        foreach ($phpDocNode->getTypeAliasImportTagValues() as $importTag) {
            $localName = $importTag->importedAs ?? $importTag->importedAlias;
            $fqcnSource = SpecialTypeResolver::resolveFqcn($importTag->importedFrom->name, $ref);
            $resolvedType = self::resolveImportedTypeAlias($fqcnSource, $importTag->importedAlias);
            if ($resolvedType !== null) {
                $aliases[$localName] = $resolvedType;
            }
        }
    }

    /**
     * Resolves an imported type alias (@phpstan-import-type) from a target class or interface, recursively handling chained imports.
     */
    private static function resolveImportedTypeAlias(string $fqcn, string $importedAlias): ?TypeNode
    {
        if (! ClassNameValidator::isValid($fqcn) || (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn))) {
            return null;
        }

        try {
            $ref = new \ReflectionClass($fqcn);
            $doc = $ref->getDocComment();

            if ($doc !== false) {
                $doc = DocblockNormalizer::normalize($doc);
                [$phpDocParser, $lexer] = self::getParserComponents();

                $tokens = new TokenIterator($lexer->tokenize($doc));
                $phpDocNode = $phpDocParser->parse($tokens);

                foreach ($phpDocNode->getTypeAliasTagValues() as $aliasTag) {
                    if ($aliasTag->alias === $importedAlias) {
                        return $aliasTag->type;
                    }
                }

                foreach ($phpDocNode->getTypeAliasImportTagValues() as $importTag) {
                    $localName = $importTag->importedAs ?? $importTag->importedAlias;
                    if ($localName === $importedAlias) {
                        $nextFqcn = SpecialTypeResolver::resolveFqcn($importTag->importedFrom->name, $ref);

                        return self::resolveImportedTypeAlias($nextFqcn, $importTag->importedAlias);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore unresolvable types
        }

        return null;
    }
}