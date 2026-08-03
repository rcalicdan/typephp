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
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        if (! ($typeNode instanceof CallableTypeNode)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));

        // Enforce Closure instance for Closure, \Closure, pure-Closure, static-closure, etc.
        if (str_contains($identifierName, 'closure') && ! ($callable instanceof \Closure)) {
            throw ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be of type Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given');
        }

        // Enforce static Closure (not bound to $this) for static-closure or static-pure-closure
        if (str_contains($identifierName, 'static') && $callable instanceof \Closure) {
            $refFunc = new \ReflectionFunction($callable);
            if ($refFunc->getClosureThis() !== null) {
                throw ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a static Closure (not bound to $this)');
            }
        }

        return function (...$args) use ($callable, $typeNode, $registry, $function, $paramName) {
            $argCount = count($args);

            foreach ($typeNode->parameters as $index => $paramNode) {
                // Variadic Callback Parameters (e.g. float ...$floats)
                if ($paramNode->isVariadic) {
                    for ($vIdx = $index; $vIdx < $argCount; $vIdx++) {
                        $err = $registry->validate($args[$vIdx], $paramNode->type, "$function(): Callback \$$paramName variadic argument #" . ($vIdx + 1));
                        if ($err !== null) {
                            throw $err;
                        }
                    }

                    break;
                }

                // Positional / Optional Callback Parameters (e.g. int, int=)
                if (array_key_exists($index, $args)) {
                    $err = $registry->validate($args[$index], $paramNode->type, "$function(): Callback \$$paramName argument #" . ($index + 1));
                    if ($err !== null) {
                        throw $err;
                    }
                }
            }

            $result = $callable(...$args);

            // Callback Return Type Validation
            $err = $registry->validate($result, $typeNode->returnType, "$function(): Callback \$$paramName return value");
            if ($err !== null) {
                throw $err;
            }

            return $result;
        };
    }
}