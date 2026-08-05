<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Liskov;

class RenamedParamImplementation implements RenamedParamInterface
{
    // Renames parameter from $id to $userId
    public function find(int $userId): bool
    {
        return true;
    }
}
