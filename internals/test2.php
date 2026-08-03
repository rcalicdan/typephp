<?php

declare(strict_types=1);

class Animal
{
}
class Dog extends Animal
{
}
class Puppy extends Dog
{
}   // subtype of Dog, for variance testing
class Cat extends Animal
{
}
class Car
{
}
class SportsCar extends Car
{
}

// -------------------------------------------------------------
// TEST D: Variance — does T=Dog accept a Puppy (Dog subclass)?
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
// TEST E: Multiple independent template params in one signature
// -------------------------------------------------------------
/**
 * @template T of Animal
 * @template U of Car
 *
 * @param T $animal
 * @param U $car
 * @param T $animal2
 * @param U $car2
 */
function pairUp(mixed $animal, mixed $car, mixed $animal2, mixed $car2): void
{
    // no-op
}

// -------------------------------------------------------------
// TEST F: Nested generics — Box<Box<Dog>>
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
// TEST G: Performance under load
// -------------------------------------------------------------

// -------------------------------------------------------------
// TEST H: Unbinding / re-binding across separate calls
// -------------------------------------------------------------

echo "=== TEST D: Variance (bound=Dog, arg=Puppy subclass) ===\n";

try {
    $result = pickFirst(new Dog(), new Puppy());
    echo "   ⚠️  pickFirst(Dog, Puppy) PASSED — library accepts subtypes of bound T (covariant-ish behavior)\n";
} catch (TypeError $e) {
    echo "   ⚠️  pickFirst(Dog, Puppy) FAILED — library demands exact type match for bound T\n";
    echo '       ' . $e->getMessage() . "\n";
}
echo "   (Neither outcome is wrong — just confirms which design your library implements)\n";

echo "\n=== TEST E: Multiple independent template params (T, U) ===\n";

// Valid: T=Dog throughout, U=Car throughout
try {
    pairUp(new Dog(), new Car(), new Dog(), new Car());
    echo "   ✅ pairUp(Dog, Car, Dog, Car) passed — T and U each independently consistent\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Invalid: T flips from Dog to Cat (T inconsistency), U stays Car
try {
    pairUp(new Dog(), new Car(), new Cat(), new Car());
    echo "   ❌ Failed to catch: T changed from Dog to Cat but U was fine\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR (T leak): ' . $e->getMessage() . "\n";
}

// Invalid: T stays Dog, but U flips from Car to SportsCar (subtype) — tests U independence + variance on U
try {
    pairUp(new Dog(), new Car(), new Dog(), new SportsCar());
    echo "   ⚠️  pairUp(..., Car, ..., SportsCar) passed — U accepted a Car subtype\n";
} catch (TypeError $e) {
    echo "   ⚠️  pairUp(..., Car, ..., SportsCar) failed — U demanded exact match\n";
    echo '       ' . $e->getMessage() . "\n";
}

// Invalid: T and U swapped types entirely — T given a Car, U given an Animal
try {
    pairUp(new Car(), new Dog(), new Car(), new Dog());
    echo "   ❌ Failed to catch: T/U bounds swapped (T got Car, should need Animal bound; U got Dog, should need Car bound)\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR (bound violation, not just consistency): ' . $e->getMessage() . "\n";
}

echo "\n=== TEST F: Nested generics — Box<Box<Dog>> ===\n";

try {
    $innerBox = new Box(new Dog());
    $outerBox = new Box($innerBox); // T for outer Box should resolve to "Box<Dog>", not just "Animal"
    echo "   ✅ Box(Box(Dog)) constructed without error\n";

    $unwrapped = $outerBox->get();
    if ($unwrapped instanceof Box) {
        echo "   ℹ️  outerBox->get() returned a Box instance (nested structure preserved)\n";
        $innerVal = $unwrapped->get();
        echo '   ℹ️  innerBox->get() returned instance of: ' . get_class($innerVal) . "\n";
    } else {
        echo "   ⚠️  outerBox->get() did NOT return a Box — nested T may not be tracked, just treated as 'mixed'\n";
    }
} catch (TypeError $e) {
    echo '   ⚠️  Box(Box(Dog)) threw unexpectedly: ' . $e->getMessage() . "\n";
    echo "       (This may mean Box<T of Animal> rejects a Box itself, since Box is not an Animal)\n";
}

echo "\n=== TEST G: Performance under load (100,000 calls) ===\n";

$iterations = 100_000;
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    pickFirst(new Dog(), new Dog());
}
$elapsed = microtime(true) - $start;
$perCall = ($elapsed / $iterations) * 1_000_000; // microseconds

echo "   {$iterations} calls in " . number_format($elapsed, 4) . "s\n";
echo '   ~' . number_format($perCall, 2) . " microseconds per call\n";

// Compare against a plain non-generic baseline for context
function plainPick(Animal $a, Animal $b): Animal
{
    return $a;
}

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    plainPick(new Dog(), new Dog());
}
$elapsedPlain = microtime(true) - $start;
$perCallPlain = ($elapsedPlain / $iterations) * 1_000_000;

echo '   Baseline (native type-hints, no template): ~' . number_format($perCallPlain, 2) . " microseconds per call\n";
echo '   Overhead multiplier: ' . number_format($perCall / max($perCallPlain, 0.0001), 1) . "x\n";

echo "\n=== TEST H: T scoping across separate, unrelated calls ===\n";

// Call 1: bind T=Cat in one call
try {
    pickFirst(new Cat(), new Cat());
    echo "   ✅ Call 1: pickFirst(Cat, Cat) passed, T=Cat for this call\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR on Call 1: ' . $e->getMessage() . "\n";
}

// Call 2: immediately after, bind T=Dog in a totally separate call
// If T leaked from Call 1 (cached as Cat somewhere it shouldn't be),
// this would incorrectly fail or behave oddly.
try {
    pickFirst(new Dog(), new Dog());
    echo "   ✅ Call 2: pickFirst(Dog, Dog) passed, T=Dog — no leakage from Call 1's T=Cat\n";
} catch (TypeError $e) {
    echo '   ❌ LEAKAGE BUG: Call 2 failed, suggesting T from Call 1 (Cat) leaked into Call 2: ' . $e->getMessage() . "\n";
}

// Call 3: interleave Box<Dog> and Box<Cat> instances and confirm each tracks
// its own T independently (no shared/static state between instances)
$dogBox = new Box(new Dog());
$catBox = new Box(new Cat());

try {
    $dogBox->set(new Dog()); // should pass
    echo "   ✅ dogBox->set(Dog) passed\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $catBox->set(new Cat()); // should pass
    echo "   ✅ catBox->set(Cat) passed\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $dogBox->set(new Cat()); // should fail — dogBox's T is Dog
    echo "   ❌ LEAKAGE BUG: dogBox accepted a Cat (its T should be locked to Dog)\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: dogBox correctly rejected Cat: ' . $e->getMessage() . "\n";
}

try {
    $catBox->set(new Dog()); // should fail — catBox's T is Cat
    echo "   ❌ LEAKAGE BUG: catBox accepted a Dog (its T should be locked to Cat)\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: catBox correctly rejected Dog: ' . $e->getMessage() . "\n";
}

echo "\n🎉 DONE\n";
