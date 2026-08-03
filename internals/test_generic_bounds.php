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

class Car
{
}

// -------------------------------------------------------------
// 1. Function Parameter Generic Bounds (@template T of Animal)
// -------------------------------------------------------------
/**
 * @template T of Animal
 *
 * @param T $item
 *
 * @return T
 */
function processAnimal(mixed $item): mixed
{
    return $item;
}

// -------------------------------------------------------------
// 2. Class-Level Generic Bounds (@template T of Animal)
// -------------------------------------------------------------
/**
 * @template T of Animal
 */
class AnimalContainer
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

// -------------------------------------------------------------
// 3. Unbound Template Return Fallback (@template T of Animal)
// -------------------------------------------------------------
/**
 * @template T of Animal
 *
 * @return T
 */
function createAnimal(string $type): mixed
{
    if ($type === 'dog') {
        return new Dog();
    }

    return new Car(); // Should fail! Car is not an Animal!
}

echo "=== Testing Generic / Template Bounds (@template T of Type) ===\n\n";

// --- TEST 1: Function Parameter Bounds ---
echo "1. Testing Function Parameter Bounds...\n";

// Valid: Dog extends Animal
processAnimal(new Dog());
echo "   ✅ Dog passed for @template T of Animal!\n";

// Invalid: Car is not an Animal
try {
    processAnimal(new Car());
    echo "   ❌ Failed to catch invalid argument for @template T of Animal!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// --- TEST 2: Class-Level Generic Bounds ---
echo "\n2. Testing Class-Level Generic Bounds...\n";

// Valid: AnimalContainer(Dog)
$dogBox = new AnimalContainer(new Dog());
echo "   ✅ AnimalContainer(Dog) passed!\n";

// Invalid: AnimalContainer(Car)
try {
    $carBox = new AnimalContainer(new Car());
    echo "   ❌ Failed to catch invalid class-level template bound for AnimalContainer(Car)!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// --- TEST 3: Return Type Fallback for Unbound Templates ---
echo "\n3. Testing Unbound Return Type Fallback...\n";

// Valid: Returns Dog (which is an Animal)
try {
    createAnimal('dog');
    echo "   ✅ createAnimal('dog') returned Dog (satisfies Animal bound)!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR on valid return: ' . $e->getMessage() . "\n";
}

// Invalid: Returns Car (not an Animal)
try {
    createAnimal('car');
    echo "   ❌ Failed to catch invalid return value for unbound @template T of Animal!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 ALL GENERIC BOUND TESTS COMPLETED!\n";
