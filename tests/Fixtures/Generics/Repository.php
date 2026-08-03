<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
class Repository
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}
