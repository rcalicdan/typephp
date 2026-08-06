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
// Box with NO bound — T can be anything, including another Box
// -------------------------------------------------------------
/**
 * @template T
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
// A container that explicitly expects to hold multiple T's,
// to test array-of-T alongside nested Box<T>
// -------------------------------------------------------------
/**
 * @template T
 */
class Pack
{
    /**
     * @param T[] $items
     */
    public function __construct(public array $items)
    {
    }

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }
}

echo "=== TEST F-2: Nested generics — Box<Box<Dog>> (unbounded T) ===\n";

try {
    $innerBox = new Box(new Dog());     // Box<Dog>
    $outerBox = new Box($innerBox);     // Box<Box<Dog>> — T bound to "Box" (or ideally "Box<Dog>")
    echo "   ✅ Box(Box(Dog)) constructed without error\n";

    $unwrapped = $outerBox->get();
    if ($unwrapped instanceof Box) {
        echo "   ✅ outerBox->get() returned a Box instance — nested structure preserved\n";
        $innerVal = $unwrapped->get();
        echo '   ℹ️  innerBox->get() returned instance of: ' . \get_class($innerVal) . "\n";
    } else {
        echo "   ⚠️  outerBox->get() did NOT return a Box\n";
    }
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Now test whether T, once bound to "Box" (from the Dog-holding box),
// enforces consistency the same way scalar T's did earlier —
// i.e. does outerBox reject being set to a Box holding something else,
// or does "T = Box" mean ANY Box qualifies, regardless of what's inside it?
echo "\n   -- Sub-test: does outer T track WHAT KIND of Box, or just 'a Box'? --\n";

try {
    $anotherInnerBox = new Box(new Cat()); // Box<Cat>, different inner type
    $outerBox->set($anotherInnerBox);
    echo "   ℹ️  outerBox->set(Box<Cat>) passed — T is tracked only as 'Box', not 'Box<Dog>' specifically\n";
    echo "       (This tells us whether nesting is type-erased at one level or fully recursive)\n";
} catch (TypeError $e) {
    echo "   ℹ️  outerBox->set(Box<Cat>) FAILED — T is tracked recursively as 'Box<Dog>', rejecting Box<Cat>\n";
    echo '       ' . $e->getMessage() . "\n";
}

// Sanity check: outer T should still reject a non-Box entirely (e.g. a plain Dog),
// since T was bound to "Box" (or Box<Dog>) on first use, not "Animal"
try {
    $outerBox->set(new Dog());
    echo "   ❌ POSSIBLE ISSUE: outerBox accepted a plain Dog, even though T was bound to Box on construction\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: outerBox correctly rejected a plain Dog (T is bound to Box, not Animal): ' . $e->getMessage() . "\n";
}

echo "\n=== TEST F-3: T[] resolving through a nested container (Pack<Box<Dog>>) ===\n";

try {
    $box1 = new Box(new Dog());
    $box2 = new Box(new Dog());
    $pack = new Pack([$box1, $box2]); // Pack<Box<Dog>>, T[] should mean "array of Box"
    echo "   ✅ Pack([Box(Dog), Box(Dog)]) constructed — T[] resolved to array of Box\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Now try adding a mismatched element — a Box<Cat> instead of Box<Dog>,
// or a raw Dog instead of a Box at all
try {
    $pack->add(new Dog()); // raw Dog, not wrapped in a Box — should fail if T = Box
    echo "   ❌ POSSIBLE ISSUE: Pack accepted a raw Dog even though T should be 'Box' based on constructor items\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: Pack correctly rejected a raw Dog (T is Box, not Animal): ' . $e->getMessage() . "\n";
}

try {
    $box3 = new Box(new Cat());
    $pack->add($box3); // Box<Cat> — does Pack's T care about what's inside the Box?
    echo "   ℹ️  Pack->add(Box<Cat>) passed — T tracked only as 'Box' at this level, inner type not enforced across Pack\n";
} catch (TypeError $e) {
    echo "   ℹ️  Pack->add(Box<Cat>) FAILED — T tracked recursively, rejecting Box<Cat> when Pack established Box<Dog>\n";
    echo '       ' . $e->getMessage() . "\n";
}

echo "\n🎉 DONE — key question: does nesting stop at one level (T='Box') or resolve recursively (T='Box<Dog>')?\n";
