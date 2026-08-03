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

class User
{
    public function __construct(public string $name)
    {
    }
}

class Product
{
    public function __construct(public string $sku)
    {
    }
}

/**
 * @template T
 */
class Collection
{
    /**
     * @var T[]
     */
    private array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * @return T[]
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}

echo "=== TEST I: Prebinding via @var docblock — /** @var Collection<User> */ ===\n\n";

echo "-- Sub-test 1: prebound Collection<User>, add(User) should pass --\n";
/** @var Collection<User> $users */
$users = new Collection();

try {
    $users->add(new User('Alice'));
    echo "   ✅ users->add(User) passed — T prebound to User via docblock\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n-- Sub-test 2: same prebound Collection<User>, add(Product) should fail --\n";

try {
    $users->add(new Product('SKU-123'));
    echo "   ❌ Failed to catch: Collection<User> accepted a Product\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n-- Sub-test 3: separate Collection<Product> instance, independent of Collection<User> --\n";
/** @var Collection<Product> $products */
$products = new Collection();

try {
    $products->add(new Product('SKU-456'));
    echo "   ✅ products->add(Product) passed\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $products->add(new User('Bob'));
    echo "   ❌ Failed to catch: Collection<Product> accepted a User\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n-- Sub-test 4: does adding a second valid User to the SAME collection still respect T? --\n";

try {
    $users->add(new User('Carol'));
    echo "   ✅ users->add(User) #2 passed — T=User still enforced consistently on this instance\n";
    echo '   ℹ️  users->count() = ' . $users->count() . "\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n-- Sub-test 5: toArray() return — are the contents actually User instances? --\n";
$all = $users->toArray();
$allAreUsers = array_reduce($all, fn ($carry, $item) => $carry && $item instanceof User, true);
echo '   ℹ️  toArray() returned ' . count($all) . ' items, all instanceof User: ' . ($allAreUsers ? 'yes' : 'no') . "\n";

echo "\n-- Sub-test 6: no @var annotation at all — does T fall back to unbound/inferred-from-first-add? --\n";
$mystery = new Collection(); // no docblock this time

try {
    $mystery->add(new Dog());
    echo "   ✅ mystery->add(Dog) passed (no prebinding, first add establishes T=Dog?)\n";
} catch (TypeError $e) {
    echo '   ℹ️  mystery->add(Dog) failed even with no @var — ' . $e->getMessage() . "\n";
}

try {
    $mystery->add(new Cat());
    echo "   ℹ️  mystery->add(Cat) passed after Dog — T not locked without explicit @var prebinding\n";
} catch (TypeError $e) {
    echo "   ℹ️  mystery->add(Cat) failed after Dog — T locked to Dog from first add, even without @var\n";
    echo '       ' . $e->getMessage() . "\n";
}

echo "\n🎉 DONE — key things to look at:\n";
echo "   1. Does @var Collection<User> actually restrict add() before any item exists? (sub-tests 1-2)\n";
echo "   2. Are two separate prebound instances independent? (sub-test 3)\n";
echo "   3. Without @var, does behavior degrade gracefully (either: no enforcement, or: infer T from first add)? (sub-test 6)\n";
