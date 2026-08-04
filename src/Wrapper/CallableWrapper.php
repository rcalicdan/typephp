<?php

declare(strict_types=1);

namespace TypePHP\Wrapper;

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\TypeFormatter;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * Wraps callables to enforce argument and return type contracts dynamically at runtime.
 */
final class CallableWrapper
{
    /**
     * Resolves callable contract metadata for a function parameter or return value and wraps the callable.
     */
    public static function wrap(string $function, string $paramName, mixed $callable, TypeValidatorRegistry $registry): mixed
    {
        if (! is_callable($callable)) {
            return $callable;
        }

        $contract = ContractParser::parse($function);
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        $prefix = ($paramName === 'return') ? "$function(): Return value" : "$function(): Callback \$$paramName";

        return self::wrapTypeNode($typeNode, $callable, $prefix, $registry);
    }

    /**
     * Wraps a callable with runtime argument and return value type validation based on a CallableTypeNode AST.
     *
     * Performs the following steps:
     * 1. Validates Closure type restrictions (Closure vs static-closure).
     * 2. Returns an interceptor closure that validates arguments before invocation.
     * 3. Validates return value after invocation and recursively wraps returned callbacks.
     */
    public static function wrapTypeNode(?TypeNode $typeNode, mixed $callable, string $prefix, TypeValidatorRegistry $registry): mixed
    {
        if (! is_callable($callable) || ! ($typeNode instanceof CallableTypeNode)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));
        self::enforceClosureConstraints($identifierName, $callable, $prefix);

        return function (...$args) use ($callable, $typeNode, $registry, $prefix) {
            self::validateCallbackArguments($typeNode, $args, $prefix, $registry);

            $result = $callable(...$args);

            $err = $registry->validate($result, $typeNode->returnType, "$prefix return value");
            if ($err !== null) {
                throw ErrorFactory::prepareException(new \TypeError($err->getMessage()));
            }

            if ($typeNode->returnType instanceof CallableTypeNode && is_callable($result)) {
                $result = self::wrapTypeNode($typeNode->returnType, $result, "$prefix: Returned callback", $registry);
            }

            return $result;
        };
    }

    /**
     * Enforces strict Closure and static-closure constraints on the provided callable.
     */
    private static function enforceClosureConstraints(string $identifierName, mixed $callable, string $prefix): void
    {
        if (str_contains($identifierName, 'closure') && ! ($callable instanceof \Closure)) {
            throw ErrorFactory::prepareException(new \TypeError($prefix . ' must be of type Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given'));
        }

        if (str_contains($identifierName, 'static') && $callable instanceof \Closure) {
            $refFunc = new \ReflectionFunction($callable);
            if ($refFunc->getClosureThis() !== null) {
                throw ErrorFactory::prepareException(new \TypeError($prefix . ' must be a static Closure (not bound to $this)'));
            }
        }
    }

    /**
     * Validates variadic and positional arguments passed into an intercepted callback.
     *
     * @param array<int|string, mixed> $args
     */
    private static function validateCallbackArguments(CallableTypeNode $typeNode, array $args, string $prefix, TypeValidatorRegistry $registry): void
    {
        $argCount = count($args);

        foreach ($typeNode->parameters as $index => $paramNode) {
            if ($paramNode->isVariadic) {
                for ($vIdx = $index; $vIdx < $argCount; $vIdx++) {
                    $err = $registry->validate($args[$vIdx], $paramNode->type, "$prefix variadic argument #" . ($vIdx + 1));
                    if ($err !== null) {
                        throw ErrorFactory::prepareException(new \TypeError($err->getMessage()));
                    }
                }

                break;
            }

            if (array_key_exists($index, $args)) {
                $err = $registry->validate($args[$index], $paramNode->type, "$prefix argument #" . ($index + 1));
                if ($err !== null) {
                    throw ErrorFactory::prepareException(new \TypeError($err->getMessage()));
                }
            }
        }
    }
}