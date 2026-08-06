<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\DeepInheritance;

interface DeepRootInterface
{
    /**
     * @param positive-int $code
     */
    public function executeDeep(int $code): bool;
}
