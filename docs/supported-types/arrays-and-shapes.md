# Arrays & Shapes

TypePHP provides runtime enforcement for sequential lists, key-value generic maps, typed class arrays, positional tuples, sealed and unsealed array shapes, and object shapes.

---

## Sequential Lists (`list<T>` & `non-empty-list<T>`)

A `list<T>` represents a sequential, 0-indexed integer key array without gaps. TypePHP validates lists at runtime using PHP's native `array_is_list()` function:

```php
<?php

declare(strict_types=1);

/**
 * @param list<non-empty-string> $tags
 * @param non-empty-list<positive-int> $scores
 */
function processList(array $tags, array $scores): void
{
    // ...
}

// Valid Call
processList(['php', 'pest', 'typephp'], [10, 20, 30]);

// Invalid Call (Associative array passed where list was expected)
processList(['tag1' => 'php'], [10, 20]);
// Throws: TypeError: processList(): Argument $tags must be a list

// Invalid Call (Empty array passed where non-empty-list was expected)
processList(['php'], []);
// Throws: TypeError: processList(): Argument $scores must be a non-empty list
```

---

## Key-Value Generic Arrays (`array<K, V>` & `T[]`)

TypePHP enforces specific key and value types on associative or indexed arrays:

### Generic Key-Value Arrays (`array<K, V>`)

```php
/**
 * @param array<string, positive-int> $userScores
 */
function recordScores(array $userScores): void
{
    // ...
}

// Valid Call
recordScores(['alice' => 100, 'bob' => 95]);

// Invalid Call (Key '0' is integer instead of string)
recordScores([0 => 100]);
// Throws: TypeError: recordScores(): Argument $userScores key must be of type string

// Invalid Call (Value -5 violates positive-int)
recordScores(['alice' => -5]);
// Throws: TypeError: recordScores(): Argument $userScores['alice'] must be of type positive-int
```

### Typed Scalar, Refinement, & Callable Arrays (`positive-int[]`, `non-empty-string[]`, `callable[]`)

In addition to typed class arrays (`User[]`), TypePHP validates arrays of primitives, scalar refinements, callables, or shapes using `T[]` syntax:

```php
/**
 * @param positive-int[] $ids
 * @param non-empty-string[] $tags
 * @param callable[] $callbacks
 * @param array{id: positive-int}[] $userShapes
 */
function processTypedArrays(array $ids, array $tags, array $callbacks, array $userShapes): void
{
    // ...
}

// Valid Call
processTypedArrays(
    ids: [10, 20, 30],
    tags: ['php', 'pest'],
    callbacks: [fn () => null, 'strlen'],
    userShapes: [['id' => 1], ['id' => 2]]
);

// Invalid Call (-50 violates positive-int[])
processTypedArrays(
    ids: [10, -50, 30],
    tags: ['php', 'pest'],
    callbacks: [fn () => null],
    userShapes: [['id' => 1]]
);
// Throws: TypeError: processTypedArrays(): Argument $ids[1] must be of type positive-int, negative int (-50) given
```

> **Performance Optimization:** When validating arrays of objects (such as `User[]`), TypePHP memoizes previously checked object instances in `\WeakMap`. If the same object instance appears multiple times in a collection, its type is checked once and retrieved in O(1) time on subsequent accesses.

---

## Deeply Nested Arrays & Lists (`array<K, list<V>>`)

TypePHP recursively validates deeply nested array structures down to any depth:

```php
/**
 * @param array<string, list<positive-int>> $matrix
 */
function processMatrix(array $matrix): void
{
    // ...
}

// Valid Call
processMatrix([
    'math' => [100, 95],
    'science' => [88, 92],
]);

// Invalid Call (Nested list item -50 violates positive-int)
processMatrix([
    'math' => [100, -50],
]);
// Throws: TypeError: processMatrix(): Argument $matrix['math'][1] must be of type positive-int
```

---

## Generics inside Typed Arrays & Shapes (`list<Producer<T>>`)

TypePHP validates generic container objects nested inside arrays or array shapes:

```php
use App\Generics\Producer;
use App\Models\Dog;
use App\Models\Car;

/**
 * @param list<Producer<Dog>> $producers
 * @param array{items: list<Producer<covariant Animal>>, count: positive-int} $payload
 */
function processGenericList(array $producers, array $payload): void
{
    // ...
}

// Valid Call
processGenericList(
    [new Producer(new Dog()), new Producer(new Dog())],
    ['items' => [new Producer(new Dog())], 'count' => 1]
);

// Invalid Call (Producer holds Car instead of Dog)
processGenericList(
    [new Producer(new Dog()), new Producer(new Car())],
    ['items' => [new Producer(new Dog())], 'count' => 1]
);
// Throws: TypeError: processGenericList(): Argument $producers[1] must be an instance of Producer<Dog>
```

---

## Array Shapes (`array{key: type}`)

Array shapes define exact key-value contracts for associative arrays.

### Required vs. Optional Keys

Mark optional keys with a question mark (`key?: type`):

```php
/**
 * @param array{id: positive-int, username: non-empty-string, role?: 'admin'|'user'} $payload
 */
function saveUserPayload(array $payload): void
{
    // ...
}

// Valid Call (Optional 'role' key omitted)
saveUserPayload(['id' => 10, 'username' => 'Alice']);

// Valid Call (Optional 'role' key provided)
saveUserPayload(['id' => 10, 'username' => 'Alice', 'role' => 'admin']);

// Invalid Call (Missing required 'username' key)
saveUserPayload(['id' => 10]);
// Throws: TypeError: saveUserPayload(): Argument $payload is missing required key 'username'
```

### Sealed vs. Unsealed Shapes

By default, array shapes are **sealed**. Any unexpected extra keys in the array will trigger a `TypeError`.

To allow additional dynamic keys, define an **unsealed shape** using `...<K, V>` syntax:

```php
/**
 * Unsealed Shape: Requires 'id', but permits additional string-string pairs
 *
 * @param array{id: positive-int, ...<string, string>} $options
 */
function processUnsealedOptions(array $options): void
{
    // ...
}

// Valid Call (Includes extra string key 'category')
processUnsealedOptions(['id' => 10, 'category' => 'admin']);

// Invalid Call (Extra key 'code' has integer value 999 instead of string)
processUnsealedOptions(['id' => 10, 'code' => 999]);
// Throws: TypeError: processUnsealedOptions(): Argument $options['code'] must be of type string
```

---

## Positional Tuple Shapes (`array{0: T1, 1: T2}`)

Define fixed-length, positional array tuples:

```php
/**
 * @param array{0: positive-int, 1: non-empty-string} $tuple
 */
function processTuple(array $tuple): void
{
    // ...
}

// Valid Call
processTuple([100, 'success']);

// Invalid Call (Index 0 is negative integer)
processTuple([-5, 'success']);
// Throws: TypeError: processTuple(): Argument $tuple['0'] must be of type positive-int
```

---

## Object Shapes (`object{prop: type}` & `stdClass{prop: type}`)

Define property shape contracts for generic objects or strictly for `stdClass` instances:

### Generic Object Shapes (`object{prop: type}`)

Accepts any object or `stdClass` matching the property shape:

```php
/**
 * @param object{id: positive-int, name: non-empty-string} $user
 */
function processObjectShape(object $user): void
{
    // ...
}

$std = new stdClass();
$std->id = 42;
$std->name = 'Alice';

processObjectShape($std); // Valid
```

### Strict `stdClass` Shapes (`stdClass{prop: type}`)

Strictly requires a `stdClass` instance, rejecting custom class instances:

```php
/**
 * @param stdClass{id: positive-int, name: non-empty-string} $payload
 */
function processStrictStdClass(object $payload): void
{
    // ...
}

// Rejects custom class instances even if they possess 'id' and 'name' properties!
class CustomUser { public int $id = 42; public string $name = 'Alice'; }

processStrictStdClass(new CustomUser());
// Throws: TypeError: processStrictStdClass(): Argument $payload must be an instance of stdClass
```
