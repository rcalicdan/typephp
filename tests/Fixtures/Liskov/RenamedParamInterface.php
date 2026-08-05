<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Liskov;

interface RenamedParamInterface
{
    /**
     * @param positive-int $id
     */
    public function find(int $id): bool;
}
