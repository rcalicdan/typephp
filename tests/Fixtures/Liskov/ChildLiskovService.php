<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Liskov;

class ChildLiskovService extends ParentLiskovService
{
    /**
     * Overrides ONLY $name docblock, leaving $id un-annotated!
     *
     * @param 'Alice'|'Bob' $name
     */
    public function update(int $id, string $name): bool
    {
        return true;
    }
}
