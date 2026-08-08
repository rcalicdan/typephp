# Callables & Closures

TypePHP provides lazy runtime interception for callbacks, Closures, array callables, first-class callables, and PHPStan static-closure specifications.

---

## How Callback Interception Works (`CallableWrapper`)

When a callable parameter or local variable is annotated with a callable contract (such as `callable(positive-int): non-empty-string`), TypePHP wraps the callable in a lazy interceptor proxy:

1. **Lazy Execution:** TypePHP does not execute the callback immediately when passed as an argument.
2. **Input Validation:** When the wrapped callback is invoked, TypePHP validates the arguments passed into the callback.
3. **Output Validation:** When the callback returns, TypePHP validates the returned value against the callback's declared return contract.

---

## Basic Callable Contracts (`callable(T1, T2): R`)

Declare argument and return types for callbacks using `callable(Type1, Type2): ReturnType` syntax:

```php
<?php

declare(strict_types=1);

/**
 * @param callable(positive-int, non-empty-string): bool $callback
 */
function processUserCallback(callable $callback): bool
{
    return $callback(10, 'Alice');
}

// Valid Callback
processUserCallback(function (int $id, string $name): bool {
    return $id > 0 && strlen($name) > 0;
});

// Invalid Callback (Returns integer 123 instead of bool)
processUserCallback(function (int $id, string $name): int {
    return 123;
});
// Throws: TypeError: Callback $callback return value must be of type bool, int (123) given
```

---

## Complex Parameter & Return Contracts in Callables

Because `CallableWrapper` delegates callback argument and return validation directly to TypePHP's central validator engine, **all complex types (generics, array shapes, lists, unions, intersections) are fully enforced inside callback signatures**:

```php
use App\Generics\Producer;
use App\Models\Dog;
use App\Models\Car;

/**
 * Callback accepting a generic Producer<Dog> and list<positive-int>, returning an array shape
 *
 * @param callable(Producer<Dog>, list<positive-int>): array{status: 'success'|'error', count: positive-int} $processor
 */
function executeComplexCallback(callable $processor): void
{
    $processor(new Producer(new Dog()), [10, 20]);
}

// Valid Call
executeComplexCallback(function (Producer $producer, array $ids): array {
    return ['status' => 'success', 'count' => 2];
});

// Invalid Execution (Callback returns negative count -5 violating positive-int in array shape)
executeComplexCallback(function (Producer $producer, array $ids): array {
    return ['status' => 'success', 'count' => -5];
});
// Throws: TypeError: Callback $processor return value['count'] must be of type positive-int, negative int (-5) given
```

---

## Strict Closure Instance Contracts (`Closure(T): R`)

When you specify `Closure(T): R` instead of `callable(T): R`, TypePHP strictly requires a native `Closure` instance, rejecting string function names or array callables:

```php
/**
 * Strictly requires a native Closure instance
 *
 * @param Closure(positive-int): non-empty-string $closure
 */
function executeClosureOnly(Closure $closure): string
{
    return $closure(42);
}

// Valid Call
executeClosureOnly(fn (int $id) => "user_{$id}");

// Invalid Call (Passing string function name 'strlen' where Closure was required)
executeClosureOnly('strlen');
// Throws: TypeError: Argument $closure must be of type Closure, string 'strlen' given
```

---

## Array & First-Class Callables (PHP 8.1+)

TypePHP seamlessly intercepts array callables and PHP 8.1+ First-Class Callable syntax (`$obj->method(...)`):

```php
class UserService
{
    public function formatUser(int $id): string
    {
        return "user_{$id}";
    }

    public static function staticFormat(int $id): string
    {
        return "static_user_{$id}";
    }
}

/**
 * @param callable(positive-int): non-empty-string $formatter
 */
function executeFormatter(callable $formatter): string
{
    return $formatter(100);
}

$service = new UserService();

// 1. Instance Method Array Callable
executeFormatter([$service, 'formatUser']); // Valid

// 2. Static Method Array Callable
executeFormatter([UserService::class, 'staticFormat']); // Valid

// 3. PHP 8.1+ First-Class Callable Syntax
executeFormatter($service->formatUser(...)); // Valid
```

---

## Advanced PHPStan Callable Specifications

TypePHP supports advanced callback specifications including variadic parameters, optional parameters, and static closures:

### Variadic Callback Parameters (`callable(T ...$items): R`)

```php
/**
 * @param callable(positive-int ...$ids): void $callback
 */
function processVariadicCallback(callable $callback): void
{
    $callback(10, 20, 30);
}

processVariadicCallback(function (int ...$ids) {
    // Valid: Every variadic argument is validated against positive-int
});
```

### Optional Callback Parameters (`callable(T1, T2=): R`)

Append an equals sign (`T=`) to denote optional callback parameters:

```php
/**
 * Second callback parameter $name is optional
 *
 * @param callable(positive-int, non-empty-string=): bool $callback
 */
function processOptionalCallback(callable $callback): bool
{
    return $callback(10); // 2nd argument omitted
}
```

### Static Closures (`static-closure`)

Enforce that a closure must be declared as `static` (not bound to `$this`):

```php
/**
 * @param static-closure(int): string $closure
 */
function processStaticClosure(Closure $closure): string
{
    return $closure(100);
}

// Valid (Static closure)
processStaticClosure(static fn (int $id) => "static_{$id}");

// Invalid (Non-static closure bound to $this)
processStaticClosure(fn (int $id) => "bound_{$id}");
// Throws: TypeError: Argument $closure must be a static Closure (not bound to $this)
```

---

## Inline `@var` Callable Contracts

Enforce argument and return contracts on local variables assigned with inline `@var` callable docblocks:

```php
/** @var callable(positive-int, non-empty-string): bool $formatter */
$formatter = fn (int $id, string $name) => strlen($name) > 0;

$formatter(10, 'Alice'); // Valid

$formatter(-5, 'Alice');
// Throws: TypeError: Variable $formatter: Callback argument #1 must be of type positive-int, negative int (-5) given
```

### Higher-Order Callables

TypePHP supports higher-order callables returning other callables, validating both outer factory arguments and inner callback returns:

```php
/** @var callable(positive-int): (callable(non-empty-string): non-empty-string) $factory */
$factory = function (int $multiplier): callable {
    return function (string $prefix) use ($multiplier): string {
        if ($prefix === 'invalid') {
            return ''; // Violates return non-empty-string!
        }

        return str_repeat($prefix, $multiplier);
    };
};

// Valid Execution
$repeat3 = $factory(3);
$result = $repeat3('abc'); // Returns 'abcabcabc'

// Invalid Factory Argument ($multiplier = -5 violates positive-int)
$factory(-5);
// Throws: TypeError: Variable $factory: Callback argument #1 must be of type positive-int, negative int (-5) given

// Invalid Inner Callback Return Value ('invalid' returns '' violating non-empty-string)
$repeat3 = $factory(3);
$repeat3('invalid');
// Throws: TypeError: Variable $factory: Callback return value must be of type non-empty-string, empty string ('') given
```