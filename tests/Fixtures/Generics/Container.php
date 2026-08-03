<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Animal;

/**
 * @template T of Animal
 */
class Container
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}