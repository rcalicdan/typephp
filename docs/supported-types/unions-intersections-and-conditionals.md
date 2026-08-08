# Unions, Intersections, Variadics, and Conditionals

TypePHP provides rich runtime enforcement for complex type algebra, including Union (`|`) types, Intersection (`&`) types, Variadic (`...$items`) parameters, and Conditional Return Types.

---

## Union Types (`A | B`)

Union types specify that a value must satisfy **at least one** of the declared type variants. TypePHP evaluates union variants sequentially from left to right.

### Scalar and Literal Value Unions

Combine scalar types, refinements, and literal string/integer values:

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int|non-empty-string|'active'|'pending' $value
 */
function processStatus(int|string $value): void
{
    // Valid for 100, 'user_100', 'active', or 'pending'
}

// Valid Calls
processStatus(100);
processStatus('active');

// Invalid Call (-50 is negative integer, 'archived' is not in literal union)
processStatus(-50);
// Throws: TypeError: processStatus(): Argument $value must be of type positive-int|non-empty-string|'active'|'pending'
```

### Tagged / Discriminated Union Array Shapes

Define discriminated unions for API response structures based on a status tag:

```php
/**
 * @return array{status: 'success', code: 200, data: array{id: positive-int}} | array{status: 'error', code: 400|500, message: non-empty-string}
 */
function handleApiResponse(bool $isSuccess, array $payload): array
{
    return $payload;
}

// Valid Success Payload
handleApiResponse(true, [
    'status' => 'success',
    'code' => 200,
    'data' => ['id' => 42],
]);

// Valid Error Payload
handleApiResponse(false, [
    'status' => 'error',
    'code' => 500,
    'message' => 'Internal Server Error',
]);

// Invalid Payload (Missing required 'data' key for success variant)
handleApiResponse(true, [
    'status' => 'success',
    'code' => 200,
]);
// Throws: TypeError: handleApiResponse(): Return value fails all union shape variants
```

---

## Intersection Types (`A & B`)

Intersection types require an object or value to satisfy **all** declared interface or shape contracts simultaneously.

> **Important Syntax Rules for Intersections:**
> 1. **Parentheses Requirement:** When combining intersections with unions or generic parameters (such as `(Countable & ArrayAccess) | (Iterator & Countable)` or `Collection<Countable & ArrayAccess>`), always enclose the intersection in parentheses.
> 2. **No Raw Unions Inside Intersections:** PHPDoc syntax rules require Disjunctive Normal Form (Unions of Intersections). Placing raw unions directly inside an intersection (such as `A & (B | C)`) is unsupported and will be ignored. Always expand and write it as a union of intersections: `(A & B) | (A & C)`.

### Interface Intersections

Enforce that an object implements multiple interfaces:

```php
/**
 * @param Countable&ArrayAccess $collection
 */
function processCollection(object $collection): void
{
    // ...
}

// Valid Call (Implements both Countable and ArrayAccess)
processCollection(new ArrayObject([1, 2, 3]));

// Invalid Call (Implements Countable only)
class CountableOnly implements Countable { public function count(): int { return 0; } }
processCollection(new CountableOnly());
// Throws: TypeError: processCollection(): Argument $collection must be of type Countable&ArrayAccess
```

### Unions of Intersections (Disjunctive Normal Form)

TypePHP supports complex parenthesized unions of intersections:

```php
/**
 * Enclose each intersection member in parentheses
 *
 * @param (Countable&ArrayAccess)|(Iterator&Countable) $payload
 */
function processPayload(object $payload): void
{
    // ...
}

// Valid Calls
processPayload(new ArrayObject([1, 2])); // Satisfies Countable & ArrayAccess
processPayload(new ArrayIterator([1, 2])); // Satisfies Iterator & Countable
```

---

## Variadic Parameter Contracts (`...$items`)

When a function parameter uses PHP's variadic syntax (`...$items`), TypePHP validates **every individual argument** passed in the variadic argument list.

### Scalar Variadics

```php
/**
 * @param positive-int ...$ids
 */
function deleteBatch(int ...$ids): void
{
    // ...
}

// Valid Call
deleteBatch(10, 20, 30);

// Invalid Call (3rd item violates positive-int)
deleteBatch(10, 20, -5);
// Throws: TypeError: deleteBatch(): Argument $ids[2] must be of type positive-int
```

### Variadic Unions and Shapes

Combine variadic parameters with unions or array shapes:

```php
/**
 * Variadic Union
 *
 * @param (Dog|Cat) ...$animals
 */
function processAnimals(Animal ...$animals): void {}

// Variadic Array Shapes
/**
 * @param array{id: positive-int, username: non-empty-string} ...$users
 */
function processUsers(array ...$users): void {}

processUsers(
    ['id' => 1, 'username' => 'Alice'],
    ['id' => 2, 'username' => 'Bob']
); // Valid

processUsers(
    ['id' => 1, 'username' => 'Alice'],
    ['id' => -5, 'username' => 'Bob'] // Invalid: id is negative
);
// Throws: TypeError: processUsers(): Argument $users[1]['id'] must be of type positive-int
```

---

## Conditional Return Types

Conditional return types dynamically select the function's return contract based on incoming parameter values or bound template types.

### Parameter-Based Conditional Return Types

Use `@return ($param is TargetType ? ReturnA : ReturnB)` to evaluate return contracts based on a parameter's value:

```php
/**
 * @param bool $asInt
 * @param mixed $value
 * @return ($asInt is true ? positive-int : non-empty-string)
 */
function formatValue(bool $asInt, mixed $value): mixed
{
    return $value;
}

// Evaluates return contract as positive-int
formatValue(true, 42); // Valid

formatValue(true, 'not_an_int');
// Throws: TypeError: formatValue(): Return value must be of type positive-int

// Evaluates return contract as non-empty-string
formatValue(false, 'hello'); // Valid

formatValue(false, '');
// Throws: TypeError: formatValue(): Return value must be of type non-empty-string
```

### Template-Based Conditional Return Types

Use `@return (T is TargetType ? ReturnA : ReturnB)` to evaluate return contracts based on an inferred template parameter `T`:

```php
/**
 * @template T
 *
 * @param T $input
 * @param mixed $value
 * @return (T is string ? positive-int : bool)
 */
function evaluateByTemplate(mixed $input, mixed $value): mixed
{
    return $value;
}

// T is inferred as string -> Return contract becomes positive-int
evaluateByTemplate('input_string', 100); // Valid

evaluateByTemplate('input_string', 'invalid_return');
// Throws: TypeError: evaluateByTemplate(): Return value must be of type positive-int

// T is inferred as int -> Return contract becomes bool
evaluateByTemplate(12345, true); // Valid

evaluateByTemplate(12345, 'not_a_bool');
// Throws: TypeError: evaluateByTemplate(): Return value must be of type bool
```
