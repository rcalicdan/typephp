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
// TEST A: T must stay consistent across multiple parameters
// -------------------------------------------------------------
/**
 * @template T of Animal
 *
 * @param T $a
 * @param T $b
 *
 * @return T
 */
function pickFirst(mixed $a, mixed $b): mixed
{
    return $a;
}

// -------------------------------------------------------------
// TEST B: T bound at construction must persist across methods
// -------------------------------------------------------------
/**
 * @template T of Animal
 */
class Box
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }

    /**
     * @return T
     */
    public function get(): mixed
    {
        return $this->item;
    }

    /**
     * @param T $item
     */
    public function set(mixed $item): void
    {
        $this->item = $item;
    }
}

// -------------------------------------------------------------
// TEST C: Array-shaped T — each element must match the *bound* T,
// not just the declared bound (Animal)
// -------------------------------------------------------------
/**
 * @template T of Animal
 *
 * @param T $seed
 * @param T[] $items
 */
function checkAll(mixed $seed, array $items): void
{
    // no-op, just testing the param validation
}

echo "=== TEST A: T consistency across parameters ===\n";

// Valid: both Dog, T = Dog throughout
try {
    pickFirst(new Dog(), new Dog());
    echo "   ✅ pickFirst(Dog, Dog) passed (T fixed to Dog)\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Invalid: Dog then Cat — both satisfy bound Animal individually,
// but T should be fixed to Dog after the first arg
try {
    pickFirst(new Dog(), new Cat());
    echo "   ❌ Failed to catch T mismatch: pickFirst(Dog, Cat) should fail (T fixed to Dog, Cat given)\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n=== TEST B: T persistence across methods on same instance ===\n";

$box = new Box(new Dog()); // T = Dog for this instance
echo "   ✅ Box(Dog) constructed, T bound to Dog\n";

// Invalid: same instance, T is Dog, calling set() with Cat should fail
try {
    $box->set(new Cat());
    echo "   ❌ Failed to catch: Box<Dog>::set(Cat) should fail (T is Dog, not Cat)\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// get() should still report/return Dog-typed value at this point
$got = $box->get();
echo '   ℹ️  Box<Dog>::get() returned instance of: ' . get_class($got) . "\n";

echo "\n=== TEST C: Array-shaped T bound consistency ===\n";

// Valid: seed is Dog, all items are Dog
try {
    checkAll(new Dog(), [new Dog(), new Dog()]);
    echo "   ✅ checkAll(Dog, [Dog, Dog]) passed\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Invalid: seed is Dog, but items contain a Cat — each item individually
// satisfies bound Animal, but not the fixed T = Dog
try {
    checkAll(new Dog(), [new Dog(), new Cat()]);
    echo "   ❌ Failed to catch: checkAll(Dog, [Dog, Cat]) should fail (T fixed to Dog, Cat in array)\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 DONE — results above show whether T is bound per-call-site/per-instance, or just checked against the raw bound (Animal) each time.\n";
