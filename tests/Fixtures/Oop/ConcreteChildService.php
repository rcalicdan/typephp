<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

class ConcreteChildService extends AbstractBaseService
{
    // No docblock here! Inherits contract from abstract parent.
    public function process(int $id): string
    {
        if ($id === 999) {
            return ''; // Violates abstract parent's @return non-empty-string!
        }

        return "item_{$id}";
    }
}
