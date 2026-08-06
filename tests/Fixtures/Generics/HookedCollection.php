<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

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
            $this->items = $value;
        }
    }

    /**
     * @param T $item
     */
    public function add(mixed $item): static
    {
        $this->items = [...$this->items, $item];

        return $this;
    }
}
