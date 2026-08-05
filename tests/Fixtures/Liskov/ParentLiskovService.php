<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Liskov;

class ParentLiskovService
{
    /**
     * @param positive-int $id
     * @param non-empty-string $name
     */
    public function update(int $id, string $name): bool
    {
        return true;
    }
}
