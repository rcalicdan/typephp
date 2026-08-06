<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\DeepInheritance;

abstract class DeepLevel1
{
    /**
     * @param positive-int $id
     *
     * @return non-empty-string
     */
    abstract public function processDeep(int $id): string;
}
