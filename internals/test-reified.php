<?php

declare(strict_types=1);

use TypePHP\TypePHP;

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
class Order
{
    public function __construct(public int $id)
    {
    }
}

/**
 * 1. Standard Generic Class (@template T)
 *
 * @template T
 */
class Collection
{
    /**
     * @var array<int, T>
     */
    public array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }
}

/**
 * 2. Custom Named Single Template (@template ItemType)
 *
 * @template ItemType
 */
class Box
{
    /**
     * @var ItemType
     */
    public mixed $item = null;
}

/**
 * 3. Multiple Templates (@template K, @template V)
 *
 * @template K
 * @template V
 */
class Dictionary
{
    /**
     * @var array<K, V>
     */
    public array $map = [];
}

/**
 * 4. Inherited Generic Class (@extends)
 *
 * @template T
 */
abstract class BaseRepository
{
    /**
     * @param T $entity
     */
    public function save(mixed $entity): void
    {
    }
}

/**
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository
{
}

echo "=== Testing Reified Generics API (TypePHP::getGenericType) ===\n\n";

// Scenario 1: Standard Single Template (Collection<User> vs Collection<Product>)
echo "1. Standard Single Template (@template T):\n";
/** @var Collection<User> $users */
$users = new Collection();

/** @var Collection<Product> $products */
$products = new Collection();

echo '   User Collection T:    ' . TypePHP::getGenericType($users) . "\n";
echo '   Product Collection T: ' . TypePHP::getGenericType($products) . "\n";
echo '   All Types Array:      ' . json_encode(TypePHP::getGenericTypes($users)) . "\n\n";

// Scenario 2: Custom Template Parameter Name (@template ItemType)
echo "2. Custom Template Parameter Name (@template ItemType):\n";
/** @var Box<Order> $orderBox */
$orderBox = new Box();

echo '   Smart Fallback Type: ' . TypePHP::getGenericType($orderBox) . "\n";
echo "   Explicit 'ItemType': " . TypePHP::getGenericType($orderBox, 'ItemType') . "\n";
echo '   All Types Array:     ' . json_encode(TypePHP::getGenericTypes($orderBox)) . "\n\n";

// Scenario 3: Multiple Template Parameters (@template K, @template V)
echo "3. Multiple Template Parameters (@template K, @template V):\n";
/** @var Dictionary<string, Product> $catalog */
$catalog = new Dictionary();

echo '   Key Template K:   ' . TypePHP::getGenericType($catalog, 'K') . "\n";
echo '   Value Template V: ' . TypePHP::getGenericType($catalog, 'V') . "\n";
echo '   All Types Array:  ' . json_encode(TypePHP::getGenericTypes($catalog)) . "\n\n";

// Scenario 4: Inherited Generics via @extends
echo "4. Inherited Generic Class (@extends BaseRepository<User>):\n";
$userRepo = new UserRepository();

echo '   Inherited Repo T: ' . TypePHP::getGenericType($userRepo) . "\n";
echo '   All Types Array:  ' . json_encode(TypePHP::getGenericTypes($userRepo)) . "\n\n";

// Scenario 5: Unannotated Generic Instance (Before First Use)
echo "5. Unannotated Generic Instance (Before First Use):\n";
$mystery = new Collection();

echo '   Unbound Type:     ' . (TypePHP::getGenericType($mystery) ?? 'null') . "\n";
echo '   All Types Array:  ' . json_encode(TypePHP::getGenericTypes($mystery)) . "\n\n";

// Scenario 6: First-Use Type Inference
echo "6. First-Use Type Inference (After First Method Call):\n";
$mystery->add(new User('Bob')); // First method call infers T = User!
echo '   Inferred Type T:  ' . TypePHP::getGenericType($mystery) . "\n";
echo '   All Types Array:  ' . json_encode(TypePHP::getGenericTypes($mystery)) . "\n";
