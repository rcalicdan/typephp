<?php

declare(strict_types=1);

class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}

/**
 * Recursive Generic Function
 *
 * @template T of Animal
 *
 * @param T $item
 * @param int $depth
 *
 * @return T
 */
function recursiveGeneric(Animal $item, int $depth = 1): Animal
{
    echo "   [Depth {$depth}] Entering with " . get_class($item) . "\n";

    if ($depth < 2) {
        echo "   [Depth {$depth}] Invoking inner recursive call with Cat...\n";
        recursiveGeneric(new Cat(), $depth + 1);
    }

    echo "   [Depth {$depth}] Exiting and returning " . get_class($item) . "\n";

    return $item;
}

/**
 * Generic Function Throwing Midway Exception
 *
 * @template T of Animal
 *
 * @param T $item
 *
 * @return T
 */
function throwingGeneric(Animal $item): Animal
{
    throw new RuntimeException("Exception thrown for " . get_class($item));
}

echo "=== TESTING RECURSION & EXCEPTION STATE ISOLATION ===\n\n";

// 1. Recursive Generic Call Test
echo "1. Testing Recursive Generic Call (Outer = Dog, Inner = Cat)...\n";
try {
    $result = recursiveGeneric(new Dog(), 1);
    echo "   ✅ SUCCESS! Outer call returned instance of: " . get_class($result) . "\n";
} catch (TypeError $e) {
    echo "   ❌ RECURSION BUG DETECTED! " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "   ❌ UNEXPECTED ERROR: " . $e->getMessage() . "\n";
}

// 2. Exception Recovery Test
echo "\n2. Testing Exception Recovery in Generic Function...\n";
try {
    throwingGeneric(new Dog());
} catch (RuntimeException $e) {
    echo "   -> Caught expected RuntimeException for Dog\n";
}

try {
    throwingGeneric(new Cat());
} catch (RuntimeException $e) {
    echo "   ✅ SUCCESS! Caught expected RuntimeException for Cat cleanly\n";
} catch (TypeError $e) {
    echo "   ❌ EXCEPTION LEAKAGE BUG DETECTED! " . $e->getMessage() . "\n";
}

echo "\n🎉 CLI TEST COMPLETED!\n";