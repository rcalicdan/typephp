<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Custom object class with declared public properties for testing Object Shapes.
 */
class UserObjectShape
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $role = null
    ) {
    }
}
