<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

interface TraitInterfaceContract
{
    /**
     * @param positive-int $code
     *
     * @return non-empty-string
     */
    public function execute(int $code): string;
}
