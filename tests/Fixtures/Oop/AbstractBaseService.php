<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

abstract class AbstractBaseService
{
    /**
     * @param positive-int $id
     *
     * @return non-empty-string
     */
    abstract public function process(int $id): string;
}
