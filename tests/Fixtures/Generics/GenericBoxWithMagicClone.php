<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Dog;

/**
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
        $this->item = new Dog();
    }
}