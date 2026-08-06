<?php

declare(strict_types=1);

class Animal
{
}
class Dog extends Animal
{
}
class Cat extends Animal
{
}

// 1. Variadic Generic Objects
/**
 * @param Producer<Animal> ...$producers
 */
function processProducers(Producer ...$producers)
{
    echo 'Successfully processed ' . \count($producers) . " producers!\n";
}

// 2. Variadic Complex Array Shapes
/**
 * @param array{id: int, name: string} ...$users
 */
function processUserShapes(array ...$users)
{
    echo 'Successfully processed ' . \count($users) . " user shapes!\n";
}

/**
 * @template-covariant T
 */
class Producer
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

echo "=== Testing Complex Variadic Arguments ===\n\n";

// Valid calls
processProducers(new Producer(new Dog()), new Producer(new Cat()));
processUserShapes(['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']);
echo "✅ Valid variadic calls passed!\n\n";

// Test 1: Invalid variadic generic object in 2nd argument
try {
    processProducers(new Producer(new Dog()), new Producer('not_an_animal'));
    echo "❌ Failed to catch bad 2nd variadic argument!\n";
} catch (TypeError $e) {
    echo '✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Test 2: Invalid variadic shape in 2nd argument (missing 'name' key)
try {
    processUserShapes(['id' => 1, 'name' => 'Alice'], ['id' => 2]);
    echo "❌ Failed to catch bad 2nd variadic shape!\n";
} catch (TypeError $e) {
    echo '✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}
