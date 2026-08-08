# Iterators & Generators

TypePHP provides lazy runtime validation for `Traversable` objects, `Iterator` instances, and PHP `Generator` functions, validating yielded keys, values, and generator inputs (`$gen->send()`) on-the-fly during iteration.

---

## How Lazy Iteration Works (`IterableWrapper` & `IteratorProxy`)

When an iterator or generator is passed into a function accepting `Traversable<K, V>` or returned from a function:
1. **Zero Memory Spikes:** TypePHP does not convert the iterator to an array or load items eagerly into RAM.
2. **On-the-Fly Validation:** Keys (`K`) and values (`V`) are validated lazily during iteration at the exact moment each item is accessed inside `current()` or `yield`.
3. **Rewindability Preserved:** `IteratorProxy` unwraps and preserves iterator rewindability, allowing multiple `foreach` loops over the same wrapped iterator without crashing.
4. **Method & Countable Forwarding:** Forwards `Countable::count()` and custom iterator methods directly to the inner iterator using `__call()`.

---

## Traversable & Iterator Contracts (`Traversable<K, V>`)

Validate keys and values on any `Traversable` or `ArrayIterator` instance:

```php
<?php

declare(strict_types=1);

/**
 * @param Traversable<non-empty-string, positive-int> $items
 */
function processTraversable(Traversable $items): array
{
    $results = [];
    foreach ($items as $key => $value) {
        $results[$key] = $value;
    }

    return $results;
}

// Valid Call
$iterator = new ArrayIterator(['item1' => 10, 'item2' => 20]);
processTraversable($iterator);

// Invalid Call (Value -50 violates positive-int)
$badIterator = new ArrayIterator(['item1' => 10, 'item2' => -50]);
processTraversable($badIterator);
// Throws: TypeError: Iterator $items value['item2'] must be of type positive-int, negative int (-50) given
```

---

## Generator Function Contracts (`Generator<TKey, TValue, TSend, TReturn>`)

PHP `Generator` functions allow declaring up to 4 generic parameters:
* **`TKey`:** Type of yielded keys (`yield $key => $val`).
* **`TValue`:** Type of yielded values (`yield $val`).
* **`TSend`:** Type of values sent into the generator via `$gen->send($val)`.
* **`TReturn`:** Type of value returned when the generator completes (`return $val`).

### Yielded Key and Value Validation (`TKey` & `TValue`)

```php
/**
 * @return Generator<non-empty-string, positive-int>
 */
function generateScores(): Generator
{
    yield 'alice' => 100; // Valid
    yield 'bob' => -50;   // Invalid: -50 violates positive-int!
}

$gen = generateScores();

foreach ($gen as $name => $score) {
    // Throws lazily on second yield:
    // TypeError: Return iterator value must be of type positive-int, negative int (-50) given
}
```

---

## Generator Input Validation (`$gen->send()` / `TSend`)

TypePHP validates values sent into a generator via `$gen->send()` against the declared `TSend` template parameter:

```php
/**
 * TKey = int, TValue = string, TSend = positive-int, TReturn = void
 *
 * @return Generator<int, string, positive-int, void>
 */
function processInteractiveGenerator(): Generator
{
    $receivedInput = yield 1 => 'first_value';
    yield 2 => "processed: {$receivedInput}";
}

$gen = processInteractiveGenerator();
$gen->current(); // Advances to first yield

// Valid Send (100 satisfies TSend = positive-int)
$gen->send(100);

// Invalid Send (-500 violates TSend = positive-int)
$gen = processInteractiveGenerator();
$gen->current();

$gen->send(-500);
// Throws: TypeError: processInteractiveGenerator(): Generator sent value (TSend) must be of type positive-int
```

---

## Delegated Generators (`yield from`)

TypePHP seamlessly intercepts delegated `yield from` expressions, lazily validating keys and values yielded from nested iterators or arrays:

```php
/**
 * @return Generator<string, positive-int>
 */
function parentGenerator(): Generator
{
    yield from ['a' => 10, 'b' => 20]; // Valid
    yield from ['c' => -99];           // Invalid: -99 violates positive-int
}

foreach (parentGenerator() as $key => $val) {
    // Throws lazily on 'c' => -99:
    // TypeError: Return iterator value must be of type positive-int
}
```

---

## Complex Yield & Send Types (Array Shapes, Generics & Lists)

Because `GeneratorChecker` delegates key, value, and `TSend` validation directly to TypePHP's central validator engine, **all complex types (array shapes, lists, generic objects, unions) are fully enforced inside generator signatures**:

```php
use App\Generics\Producer;
use App\Models\Dog;
use App\Models\Car;

/**
 * Generator yielding Array Shapes and accepting Array Shapes in $gen->send()
 *
 * @return Generator<int, array{id: positive-int, username: non-empty-string}, array{action: 'approve'|'reject'}, void>
 */
function processComplexGenerator(): Generator
{
    $input = yield 1 => ['id' => 10, 'username' => 'Alice'];
    
    // $input is validated against TSend shape array{action: 'approve'|'reject'} when sent!
}

$gen = processComplexGenerator();
$firstItem = $gen->current(); // Returns ['id' => 10, 'username' => 'Alice']

// Valid Send
$gen->send(['action' => 'approve']);

// Invalid Send ('action' => 'delete' violates 'approve'|'reject')
$gen = processComplexGenerator();
$gen->current();

$gen->send(['action' => 'delete']);
// Throws: TypeError: processComplexGenerator(): Generator sent value (TSend)['action'] must be of type ('approve' | 'reject')
```
