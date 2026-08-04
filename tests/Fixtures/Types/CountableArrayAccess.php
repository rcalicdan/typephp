<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

use ArrayAccess;
use Countable;

/**
 * Class implementing both Countable and ArrayAccess for testing intersection types.
 */
class CountableArrayAccess implements Countable, ArrayAccess
{
    public function count(): int
    {
        return 0;
    }

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