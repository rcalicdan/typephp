<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

final class ContractParser
{
    /**
     * @var array<string, array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}>
     */
    private static array $cache = [];

    /**
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>}
     */
    public static function parse(string $function): array
    {
        if (isset(self::$cache[$function])) {
            return self::$cache[$function];
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
            return self::$cache[$function] = ['types' => [], 'templates' => [], 'return' => null, 'aliases' => []];
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

            return self::$cache[$function] = [
                'types' => $types,
                'templates' => $templates,
                'return' => $returnType,
                'aliases' => $aliases,
            ];
        } catch (\Throwable $e) {
            return self::$cache[$function] = ['types' => [], 'templates' => [], 'return' => null, 'aliases' => []];
        }
    }
}
