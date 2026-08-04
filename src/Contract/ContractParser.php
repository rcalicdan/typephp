<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\DocblockNormalizer;
use TypePHP\Resolver\SpecialTypeResolver;

/**
 * Parses and caches PHPDoc contracts (@param, @return, @template, @phpstan-type) for functions and methods.
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
     * Parses PHPDoc contracts for a given function or method name.
     *
     * Performs the following steps:
     * 1. Checks static cache for previously resolved contracts.
     * 2. Reflects function or method and fetches declared docblocks.
     * 3. Parses class-level templates and type aliases if parsing a class method.
     * 4. Parses function-level templates, type aliases, parameter types, and return types.
     * 5. Falls back to class property @var docblocks for un-annotated method parameters.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    public static function parse(string $function): array
    {
        if (isset(self::$cache[$function])) {
            return self::$cache[$function];
        }

        [$ref, $doc, $classDoc] = self::reflectAndFetchDocs($function);
        $hasMethodParams = $ref instanceof \ReflectionMethod && $ref->getNumberOfParameters() > 0;

        if ($doc === null && $classDoc === null && ! $hasMethodParams) {
            return self::$cache[$function] = [
                'types' => [],
                'templates' => [],
                'return' => null,
                'aliases' => [],
            ];
        }

        try {
            [$phpDocParser, $lexer] = self::getParserComponents();

            $templates = [];
            $aliases = [];

            if ($classDoc !== null) {
                $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                $classPhpDocNode = $phpDocParser->parse($classTokens);

                foreach (self::extractTemplates($classPhpDocNode) as $templateTag) {
                    $templates[$templateTag->name] = $templateTag;
                }

                self::extractAliases($classPhpDocNode, $aliases, $ref);
            }

            $types = [];
            $returnType = null;

            if ($doc !== null) {
                $tokens = new TokenIterator($lexer->tokenize($doc));
                $phpDocNode = $phpDocParser->parse($tokens);

                foreach (self::extractTemplates($phpDocNode) as $templateTag) {
                    $templates[$templateTag->name] = $templateTag;
                }

                self::extractAliases($phpDocNode, $aliases, $ref);
                $types = self::parseParameters($phpDocNode, $ref, $function);
                $returnType = self::parseReturnType($phpDocNode, $function);
            } else {
                $types = self::parseParameters(null, $ref, $function);
            }

            return self::$cache[$function] = [
                'types' => $types,
                'templates' => $templates,
                'return' => $returnType,
                'aliases' => $aliases,
            ];
        } catch (\Throwable $e) {
            return self::$cache[$function] = [
                'types' => [],
                'templates' => [],
                'return' => null,
                'aliases' => [],
            ];
        }
    }

    /**
     * Resolves Reflection object and doccomments for a function or class method.
     *
     * @return array{\ReflectionFunction|\ReflectionMethod, ?string, ?string}
     */
    private static function reflectAndFetchDocs(string $function): array
    {
        $classDoc = null;

        if (str_contains($function, '::')) {
            [$className, $methodName] = explode('::', $function, 2);
            $ref = new \ReflectionMethod($className, $methodName);
            $fetchedClassDoc = $ref->getDeclaringClass()->getDocComment();
            $classDoc = $fetchedClassDoc !== false ? DocblockNormalizer::normalize($fetchedClassDoc) : null;
            $fetchedDoc = self::findEffectiveDocBlock($ref);
            $doc = $fetchedDoc !== null ? DocblockNormalizer::normalize($fetchedDoc) : null;
        } else {
            $ref = new \ReflectionFunction($function);
            $fetchedDoc = $ref->getDocComment();
            $doc = $fetchedDoc !== false ? DocblockNormalizer::normalize($fetchedDoc) : null;
        }

        return [$ref, $doc, $classDoc];
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
     * Parses @param tags from a PHPDoc node and resolves parameter types.
     * Falls back to property @var docblocks for matching un-annotated method parameters.
     *
     * @return array<string, TypeNode>
     */
    private static function parseParameters(
        ?PhpDocNode $phpDocNode,
        \ReflectionFunction|\ReflectionMethod $ref,
        string $function
    ): array {
        $types = [];
        $refParams = [];

        foreach ($ref->getParameters() as $p) {
            $refParams[$p->getName()] = $p->isVariadic();
        }

        if ($phpDocNode !== null) {
            foreach ($phpDocNode->getParamTagValues() as $paramTag) {
                $paramName = ltrim($paramTag->parameterName, '$');
                $type = $paramTag->type;

                $isVariadic = $paramTag->isVariadic || ($refParams[$paramName] ?? false);
                if ($isVariadic) {
                    $type = new ArrayTypeNode($type);
                }

                $types[$paramName] = SpecialTypeResolver::resolve($type, $function);
            }
        }

        if ($ref instanceof \ReflectionMethod) {
            $declaringClass = $ref->getDeclaringClass();

            foreach ($ref->getParameters() as $p) {
                $paramName = $p->getName();

                if (! isset($types[$paramName]) && $declaringClass->hasProperty($paramName)) {
                    $propertyRef = $declaringClass->getProperty($paramName);
                    $propDoc = $propertyRef->getDocComment();

                    if ($propDoc !== false) {
                        $propType = self::extractTypeFromPropertyDoc($propDoc, $paramName);
                        if ($propType !== null) {
                            $isVariadic = $refParams[$paramName] ?? false;
                            if ($isVariadic) {
                                $propType = new ArrayTypeNode($propType);
                            }

                            $types[$paramName] = SpecialTypeResolver::resolve($propType, $function);
                        }
                    }
                }
            }
        }

        return $types;
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
     * Parses @return tags from a PHPDoc node and resolves the return type.
     */
    private static function parseReturnType(?PhpDocNode $phpDocNode, string $function): ?TypeNode
    {
        if ($phpDocNode === null) {
            return null;
        }

        $returnTags = $phpDocNode->getReturnTagValues();

        if (count($returnTags) > 0) {
            return SpecialTypeResolver::resolve($returnTags[0]->type, $function);
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
        \ReflectionFunction|\ReflectionMethod $ref
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

    /**
     * Resolves effective PHPDoc for a method by searching across:
     * 1. Declaring class method
     * 2. Parent class hierarchy (Liskov Substitution Principle)
     * 3. Implemented interfaces
     * 4. Used traits
     */
    private static function findEffectiveDocBlock(\ReflectionMethod $ref): ?string
    {
        $doc = $ref->getDocComment();
        if ($doc !== false) {
            return $doc;
        }

        $methodName = $ref->getName();
        $declaringClass = $ref->getDeclaringClass();

        $parent = $declaringClass->getParentClass();
        while ($parent !== false) {
            if ($parent->hasMethod($methodName)) {
                $parentMethod = $parent->getMethod($methodName);
                $parentDoc = $parentMethod->getDocComment();
                if ($parentDoc !== false) {
                    return $parentDoc;
                }
            }
            $parent = $parent->getParentClass();
        }

        foreach ($declaringClass->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                $interfaceMethod = $interface->getMethod($methodName);
                $interfaceDoc = $interfaceMethod->getDocComment();
                if ($interfaceDoc !== false) {
                    return $interfaceDoc;
                }
            }
        }

        foreach ($declaringClass->getTraits() as $trait) {
            if ($trait->hasMethod($methodName)) {
                $traitMethod = $trait->getMethod($methodName);
                $traitDoc = $traitMethod->getDocComment();
                if ($traitDoc !== false) {
                    return $traitDoc;
                }
            }
        }

        return null;
    }
}