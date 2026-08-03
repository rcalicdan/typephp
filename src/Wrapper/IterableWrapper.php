<?php

declare(strict_types=1);

namespace TypePHP\Wrapper;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Validator\TypeValidatorRegistry;

final class IterableWrapper
{
    public static function wrap(string $function, string $paramName, mixed $iterable, TypeValidatorRegistry $registry): mixed
    {
        if (! is_iterable($iterable) || is_array($iterable)) {
            return $iterable;
        }

        $contract = ContractParser::parse($function);
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

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

        $prefix = ($paramName === 'return') ? "$function(): Return iterator" : "$function(): Iterator \$$paramName";

        $typeCheckCallback = function (mixed $key, mixed $value) use ($registry, $keyTypeNode, $itemTypeNode, $prefix): void {
            if ($keyTypeNode !== null && $key !== null) {
                $err = $registry->validate($key, $keyTypeNode, "$prefix key");
                if ($err !== null) {
                    throw $err;
                }
            }
            if ($itemTypeNode !== null) {
                $err = $registry->validate($value, $itemTypeNode, "$prefix value");
                if ($err !== null) {
                    throw $err;
                }
            }
        };

        if ($iterable instanceof \Traversable && ! ($iterable instanceof \Generator)) {
            return new IteratorProxy($iterable, $typeCheckCallback);
        }

        return (function () use ($iterable, $typeCheckCallback) {
            foreach ($iterable as $key => $value) {
                $typeCheckCallback($key, $value);
                yield $key => $value;
            }
        })();
    }
}