<?php

declare(strict_types=1);

class Animal
{
}
class Dog extends Animal
{
}
class Car
{
}

/**
 * 1. Standard Generic Class (No __clone)
 *
 * @template T
 */
class GenericBox
{
    /**
     * @var T
     */
    public mixed $item = null;

    /**
     * @param T $item
     */
    public function set(mixed $item): void
    {
        $this->item = $item;
    }
}

/**
 * 2. Generic Class with Explicit __clone() Magic Method
 *
 * @template T
 */
class GenericBoxWithMagicClone
{
    /**
     * @var T
     */
    public mixed $item = null;

    /**
     * @param T $item
     */
    public function set(mixed $item): void
    {
        $this->item = $item;
    }

    public function __clone(): void
    {
        // Assign a valid Dog instance inside __clone() so it satisfies @var T (where T = Dog)
        $this->item = new Dog();
    }
}

echo "=== Testing Clone Keyword & Generic Prebinding Preservation ===\n\n";

// TEST 1: Standard Class (No __clone)
echo "1. Standard Class (No __clone()):\n";
/** @var GenericBox<Dog> $dogBox */
$dogBox = new GenericBox();

$clonedBox = clone $dogBox;

try {
    $clonedBox->set(new Car());
    echo "   ❌ FAIL: Standard cloned box accepted Car! T = Dog was lost!\n";
} catch (TypeError $e) {
    echo "   ✅ SUCCESS: Caught expected TypeError!\n";
    echo '      Message: ' . $e->getMessage() . "\n\n";
}

// TEST 2: Class with Magic __clone()
echo "2. Class with Explicit Magic __clone():\n";
/** @var GenericBoxWithMagicClone<Dog> $magicBox */
$magicBox = new GenericBoxWithMagicClone();

$clonedMagicBox = clone $magicBox;

try {
    $clonedMagicBox->set(new Car());
    echo "   ❌ FAIL: Cloned box with __clone() accepted Car! T = Dog was lost!\n";
} catch (TypeError $e) {
    echo "   ✅ SUCCESS: Caught expected TypeError!\n";
    echo '      Message: ' . $e->getMessage() . "\n";
}
