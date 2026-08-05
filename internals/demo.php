<?php

declare(strict_types=1);

/**
 * @template T
 */
class HookedCollection
{
    /**
     * @var array<int, T>
     */
    public array $items = [] {
        set {
            $this->items = $value; // hook re-validates against @var on every full assignment
        }
    }

    /**
     * @param T $item
     */
    public function add(mixed $item): static
    {
        $this->items = [...$this->items, $item]; // full reassignment -> triggers set hook

        return $this;
    }
}

echo "--- Test 5a: add() goes through the set hook every time ---\n";

/** @var HookedCollection<int> $c */
$c = new HookedCollection();
$c->add(1);
$c->add(2);
echo "added 1, 2 OK\n";

try {
    $c->add('bad');
    echo "unexpectedly succeeded\n";
} catch (TypeError $e) {
    echo "caught via add(): {$e->getMessage()}\n";
}

echo "\n--- Test 5b: direct full-array reassignment bypassing add() ---\n";
try {
    $c->items = [1, 2, 'sneaky_bypass'];
    echo "unexpectedly succeeded — direct assignment bypassed validation!\n";
} catch (TypeError $e) {
    echo "caught via property hook directly: {$e->getMessage()}\n";
}

echo "\n--- Test 5c: in-place array mutation via offset push, does the hook fire? ---\n";
try {
    $c->items[] = 'still_sneaky'; // this is read-modify-write, NOT necessarily a full 'set'
    echo "items after offset push: " . json_encode($c->items) . "\n";
} catch (TypeError $e) {
    echo "caught: {$e->getMessage()}\n";
}