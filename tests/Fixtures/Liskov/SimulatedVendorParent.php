<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Liskov;

class SimulatedVendorParent
{
    /**
     * Buggy vendor docblock that demands negative integers!
     *
     * @param negative-int $code
     */
    public function execute(int $code): bool
    {
        return true;
    }
}
