<?php

declare(strict_types=1);

namespace TypePHP\Wrapper;

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\TypeFormatter;
use TypePHP\Validator\TypeValidatorRegistry;

final class CallableWrapper
{
    public static function wrap(string $function, string $paramName, mixed $callable, TypeValidatorRegistry $registry): mixed
    {
        if (! is_callable($callable)) {
            return $callable;
        }

        $contract = ContractParser::parse($function);
        $typeNode = $contract['types'][$paramName] ?? null;
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        if (! ($typeNode instanceof CallableTypeNode)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));
        if ($identifierName === 'closure' && ! ($callable instanceof \Closure)) {
            throw ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be an instance of Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given');
        }

        return function (...$args) use ($callable, $typeNode, $registry, $function, $paramName) {
            foreach ($typeNode->parameters as $index => $paramNode) {
                if (array_key_exists($index, $args)) {
                    $err = $registry->validate($args[$index], $paramNode->type, "$function(): Callback \$$paramName argument #" . ($index + 1));
                    if ($err !== null) {
                        throw $err;
                    }
                }
            }

            $result = $callable(...$args);

            $err = $registry->validate($result, $typeNode->returnType, "$function(): Callback \$$paramName return value");
            if ($err !== null) {
                throw $err;
            }

            return $result;
        };
    }
}
