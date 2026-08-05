<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Checker\GeneratorChecker;
use TypePHP\Internal\Checker\InlineChecker;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;
use TypePHP\Wrapper\IterableWrapper;

/**
 * Core runtime type checking engine facade for parameter validation, return type enforcement, and variable tracking.
 */
final class RuntimeTypeChecker
{
    private static ?TypeValidatorRegistry $registry = null;

    /**
     * Delegates generic template binding for class instances.
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?ErrorMessage
    {
        return TemplateManager::bindInstanceFromNode($instance, $typeNode, $context, $forceBind);
    }

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     */
    public static function checkVariable(mixed $value, string $typeString, string $varName, string $file): mixed
    {
        return InlineChecker::checkVariable($value, $typeString, $varName, $file, self::getRegistry());
    }

    /**
     * Evaluates class property validation dynamically based on configuration.
     */
    public static function checkProperty(mixed $value, mixed $objectOrClass, string $propName, string $file): mixed
    {
        return InlineChecker::checkProperty($value, $objectOrClass, $propName, $file, self::getRegistry());
    }

    /**
     * Initializes generic call frames and returns a ScopeCleaner that pops the call frame on destruction.
     *
     * @param array<string, mixed> $vars
     */
    public static function setupScope(string $function, array $vars, ?object $thisObj = null): ErrorMessage|ScopeCleaner|null
    {
        $err = self::checkParams($function, $vars, $thisObj);

        if ($err !== null) {
            if ($thisObj === null) {
                TemplateManager::popCallFrame($function);
            }

            return $err;
        }

        return $thisObj === null ? new ScopeCleaner($function) : null;
    }

    /**
     * Validates all incoming parameters against the function or method's declared contract.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkParams(string $function, array $vars, ?object $thisObj = null): ?ErrorMessage
    {
        return ParamChecker::checkParams($function, $vars, $thisObj, self::getRegistry());
    }

    /**
     * Validates a function or method's return value against its declared contract and returns value or ErrorMessage.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkReturn(string $function, mixed $value, ?object $thisObj = null, array $vars = []): mixed
    {
        return ReturnChecker::checkReturn($function, $value, $thisObj, $vars, self::getRegistry(), [self::class, 'wrapIterable']);
    }

    /**
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(string $function, mixed $sendValue): mixed
    {
        return GeneratorChecker::checkSend($function, $sendValue, self::getRegistry());
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue.
     */
    public static function checkYield(string $function, mixed $key, mixed $value): mixed
    {
        return GeneratorChecker::checkYield($function, $key, $value, self::getRegistry());
    }

    /**
     * Wraps a callable parameter to intercept calls and validate inputs/returns dynamically.
     */
    public static function wrapCallable(string $function, string $paramName, mixed $callable): mixed
    {
        return CallableWrapper::wrap($function, $paramName, $callable, self::getRegistry());
    }

    /**
     * Wraps an iterable or generator parameter to lazily validate items during iteration.
     */
    public static function wrapIterable(string $function, string $paramName, mixed $iterable): mixed
    {
        return IterableWrapper::wrap($function, $paramName, $iterable, self::getRegistry());
    }

    /**
     * Infers a TypeNode AST representation from a raw PHP value.
     */
    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        return TemplateManager::inferTypeFromValue($value);
    }

    /**
     * Returns a singleton instance of the TypeValidatorRegistry.
     */
    public static function getRegistry(): TypeValidatorRegistry
    {
        return self::$registry ??= new TypeValidatorRegistry();
    }
}
