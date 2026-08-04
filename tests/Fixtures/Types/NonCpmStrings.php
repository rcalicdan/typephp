<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Fixture class with property docblock annotation instead of constructor parameter docblock.
 */
class NonCpmStrings
{
    /**
     * @var string[]
     */
    public array $strings;

    public function __construct(array $strings)
    {
        $this->strings = $strings;
    }
}