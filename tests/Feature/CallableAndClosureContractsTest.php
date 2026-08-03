<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Services\HelperService;

/**
 * 1. Standard Callable Parameter Contract
 *
 * @param callable(positive-int, non-empty-string): bool $callback
 */
function testProcessUserCallback(callable $callback): bool
{
    return $callback(10, 'alice');
}

/**
 * 2. Callback Receiving Bad Argument Inside Function
 *
 * @param callable(int): string $callback
 */
function testExecuteBadArgumentCallback(callable $callback): mixed
{
    // Function passes a string to a callback expecting int
    return $callback('not_an_int');
}

/**
 * 3. Strict Closure Instance Parameter Contract
 *
 * @param Closure(positive-int): non-empty-string $closure
 */
function testProcessClosureOnly(Closure $closure): string
{
    return $closure(42);
}

/**
 * 4. Array Callable Parameter Contract
 *
 * @param callable(positive-int): non-empty-string $callback
 */
function testProcessArrayCallable(callable $callback): string
{
    return $callback(100);
}

/**
 * 5. Helper for Array Callable Return Type Failure Test
 *
 * @param callable(int): non-empty-string $callback
 */
function testExecuteTesterArrayCallable(callable $callback): string
{
    return $callback(-5);
}

/**
 * 6. Variadic Callback Parameters (callable(positive-int ...$ids): void)
 *
 * @param callable(positive-int ...$ids): void $callback
 */
function testVariadicCallbackParam(callable $callback): void
{
    $callback(10, 20, 30);
}

/**
 * Helper for Invalid Variadic Callback Failure Test
 *
 * @param callable(positive-int ...$ids): void $callback
 */
function testExecuteVariadicCallbackTester(callable $callback): void
{
    $callback(10, 20, -5);
}

/**
 * 7. Optional Callback Parameters (callable(positive-int, non-empty-string=): bool)
 *
 * @param callable(positive-int, non-empty-string=): bool $callback
 */
function testOptionalCallbackParam(callable $callback): bool
{
    return $callback(10); // 2nd optional argument omitted
}

/**
 * 8. Static Closure Contracts (static-closure(int): string)
 *
 * @param static-closure(int): string $closure
 */
function testStaticClosureParam(Closure $closure): string
{
    return $closure(100);
}

describe('Standard Callable Contracts (callable(T1, T2): R)', function () {
    test('executes valid callback and validates arguments and return values', function () {
        $validCallback = fn (int $id, string $name): bool => $id > 0 && strlen($name) > 0;

        expect(testProcessUserCallback($validCallback))->toBeTrue();
    });

    test('throws TypeError when callback returns a value violating PHPDoc return type', function () {
        // Returns int 123 instead of bool
        $badReturnCallback = fn (int $id, string $name): int => 123;

        expect(fn () => testProcessUserCallback($badReturnCallback))
            ->toThrow(TypeError::class, 'Callback $callback return value');
    });

    test('throws TypeError when function passes invalid argument into wrapped callback', function () {
        $callback = fn (int $id): string => "id_{$id}";

        expect(fn () => testExecuteBadArgumentCallback($callback))
            ->toThrow(TypeError::class, 'Callback $callback argument #1');
    });
});

describe('Strict Closure Instance Contracts (Closure(T): R)', function () {
    test('accepts native Closure instances', function () {
        $closure = fn (int $id): string => "user_{$id}";

        expect(testProcessClosureOnly($closure))->toBe('user_42');
    });

    test('throws TypeError when non-Closure callable (string function name) is passed', function () {
        // 'strlen' is a valid callable, but NOT an instance of Closure
        expect(fn () => testProcessClosureOnly('strlen'))
            ->toThrow(TypeError::class, 'must be of type Closure');
    });

    test('throws TypeError when non-Closure callable (array callable) is passed', function () {
        $service = new HelperService();

        expect(fn () => testProcessClosureOnly([$service, 'formatUser']))
            ->toThrow(TypeError::class, 'must be of type Closure');
    });
});

describe('Array & First-Class Callables ([$obj, "method"] & $obj->method(...))', function () {
    test('accepts valid instance method array callable', function () {
        $service = new HelperService();

        expect(testProcessArrayCallable([$service, 'formatUser']))->toBe('user_100');
    });

    test('accepts valid static method array callable', function () {
        expect(testProcessArrayCallable([HelperService::class, 'staticFormat']))->toBe('static_user_100');
    });

    test('accepts valid PHP 8.1+ first-class callable syntax', function () {
        $service = new HelperService();

        expect(testProcessArrayCallable($service->formatUser(...)))->toBe('user_100');
    });

    test('throws TypeError when method invoked via array callable returns invalid type', function () {
        $service = new HelperService();

        expect(fn () => testExecuteTesterArrayCallable([$service, 'formatUser']))
            ->toThrow(TypeError::class, 'Callback $callback return value');
    });
});

describe('Advanced PHPStan Callable Specs (Variadics, Optional Args, Static Closures)', function () {
    test('validates variadic callback arguments', function () {
        $validVariadic = fn (int ...$ids) => null;
        testVariadicCallbackParam($validVariadic);

        // Third variadic argument -5 violates positive-int
        expect(fn () => testExecuteVariadicCallbackTester($validVariadic))
            ->toThrow(TypeError::class, 'Callback $callback variadic argument #3');
    });

    test('supports optional callback parameters (int=)', function () {
        $callback = fn (int $id, ?string $name = null): bool => $id > 0;

        expect(testOptionalCallbackParam($callback))->toBeTrue();
    });

    test('accepts static closures and rejects non-static closures for static-closure', function () {
        $staticClosure = static fn (int $id): string => "static_{$id}";
        expect(testStaticClosureParam($staticClosure))->toBe('static_100');

        $nonStaticClosure = fn (int $id): string => "bound_{$id}";
        expect(fn () => testStaticClosureParam($nonStaticClosure))
            ->toThrow(TypeError::class, 'must be a static Closure');
    });
});