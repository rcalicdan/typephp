<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\TypeFormatter;

final class SpecialTypeResolver
{
    public static function checkThisIdentity(TypeNode $returnTypeNode, mixed $value, ?object $thisObj, string $function): ?\TypeError
    {
        $isThisType = ($returnTypeNode instanceof ThisTypeNode)
            || ($returnTypeNode instanceof IdentifierTypeNode && strtolower($returnTypeNode->name) === '$this');

        if ($thisObj !== null && $isThisType && $value !== $thisObj) {
            return ErrorFactory::createError($function . '(): Return value must be $this instance, ' . TypeFormatter::formatGivenValue($value) . ' returned');
        }

        return null;
    }

    public static function resolve(TypeNode $node, string $function, ?object $thisObj = null): TypeNode
    {
        $declaringClass = str_contains($function, '::') ? explode('::', $function, 2)[0] : null;
        $runtimeClass = $thisObj !== null ? get_class($thisObj) : $declaringClass;

        if ($node instanceof ThisTypeNode) {
            if ($runtimeClass !== null) {
                return new IdentifierTypeNode($runtimeClass);
            }
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);
            if ($lower === 'self' && $declaringClass !== null) {
                return new IdentifierTypeNode($declaringClass);
            }
            if ($lower === 'static' && $runtimeClass !== null) {
                return new IdentifierTypeNode($runtimeClass);
            }
            if ($lower === 'parent' && $declaringClass !== null) {
                $parentClass = get_parent_class($declaringClass);
                if ($parentClass !== false) {
                    return new IdentifierTypeNode($parentClass);
                }
            }
            if ($lower === '$this' && $runtimeClass !== null) {
                return new IdentifierTypeNode($runtimeClass);
            }
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::resolve($node->type, $function, $thisObj);
            $innerTypes = array_map(
                fn ($t) => self::resolve($t, $function, $thisObj),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $innerTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolve($node->type, $function, $thisObj));
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolve($node->type, $function, $thisObj));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::resolve($t, $function, $thisObj),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::resolve($t, $function, $thisObj),
                $node->types
            ));
        }

        return $node;
    }
}
