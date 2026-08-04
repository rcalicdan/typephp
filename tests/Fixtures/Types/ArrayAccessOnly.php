<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

use ArrayAccess;

/**
 * Class implementing ArrayAccess only for testing intersection type failures.
 */
class ArrayAccessOnly implements ArrayAccess
{
    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}
