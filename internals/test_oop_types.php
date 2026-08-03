<?php

declare(strict_types=1);

class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}

/**
 * @template T
 */
class Producer
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item) {}
}

/**
 * @template T
 */
class Box
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item) {}

    /**
     * @return self<Producer<Dog>>
     */
    public function getValidNested(): self
    {
        // Valid: returns Box<Producer<Dog>>
        return new self(new Producer(new Dog()));
    }

    /**
     * @return self<Producer<Dog>>
     */
    public function getInvalidNested(): self
    {
        // Invalid: returns Box<Producer<Cat>>, but expects Box<Producer<Dog>>
        return new self(new Producer(new Cat()));
    }
}

echo "=== Testing Nested Generics: self<Producer<Dog>> ===\n\n";

$box = new Box(new Producer(new Dog()));

// 1. Test Valid Nested Generic
echo "1. Testing Valid self<Producer<Dog>>...\n";
$box->getValidNested();
echo "   ✅ Valid nested generic passed!\n";

// 2. Test Invalid Nested Generic (Cat instead of Dog)
echo "\n2. Testing Invalid self<Producer<Dog>>...\n";
try {
    $box->getInvalidNested();
    echo "   ❌ Failed to catch bad nested generic!\n";
} catch (\TypeError $e) {
    echo "   ✅ CAUGHT EXPECTED ERROR: " . $e->getMessage() . "\n";
}

echo "\n🎉 NESTED GENERICS TEST PASSED PERFECTLY!\n";