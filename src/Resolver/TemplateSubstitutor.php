<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

final class TemplateSubstitutor
{
    /**
     * Recursively substitutes template placeholders (like T) with their bound concrete types (like int).
     *
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    public static function substitute(TypeNode $node, array $boundTemplates, array $declaredTemplates = []): TypeNode
    {
        if (count($boundTemplates) === 0 && count($declaredTemplates) === 0) {
            return $node;
        }

        if ($node instanceof IdentifierTypeNode) {
            if (isset($boundTemplates[$node->name])) {
                return $boundTemplates[$node->name];
            }

            if (isset($declaredTemplates[$node->name])) {
                $templateTag = $declaredTemplates[$node->name];

                return $templateTag->bound ?? new IdentifierTypeNode('mixed');
            }

            return $node;
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::substitute($node->type, $boundTemplates, $declaredTemplates));
        }

        if ($node instanceof GenericTypeNode) {
            $type = self::substitute($node->type, $boundTemplates, $declaredTemplates);
            $genericTypes = array_map(
                fn ($t) => self::substitute($t, $boundTemplates, $declaredTemplates),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $type instanceof IdentifierTypeNode ? $type : $node->type,
                $genericTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::substitute($node->type, $boundTemplates, $declaredTemplates));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::substitute($t, $boundTemplates, $declaredTemplates),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::substitute($t, $boundTemplates, $declaredTemplates),
                $node->types
            ));
        }

        return $node;
    }
}
