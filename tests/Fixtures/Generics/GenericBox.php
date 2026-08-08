<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
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
