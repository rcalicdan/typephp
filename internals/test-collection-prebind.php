<?php

declare(strict_types=1);

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
        return \count($this->items);
    }
}

// (Collection class same as before — omitted here for brevity, reuse from previous script)

echo "=== TEST J: Does @var actually prebind, or is it just first-use inference? ===\n\n";

echo "-- Sub-test: @var Collection<User>, but FIRST add() is a Product (no valid User has ever been added) --\n";

/** @var Collection<User> $users */
$users = new Collection();

try {
    $users->add(new Product('SKU-999')); // first call ever on this instance — should fail if @var truly prebinds
    echo "   ⚠️  users->add(Product) PASSED as the first call — this means @var is NOT enforced;\n";
    echo "       T is only inferred from first successful add(), the docblock annotation is not read at all.\n";
} catch (TypeError $e) {
    echo "   ✅ users->add(Product) FAILED as the first call — @var Collection<User> genuinely prebinds T\n";
    echo '       before any item exists: ' . $e->getMessage() . "\n";
}
