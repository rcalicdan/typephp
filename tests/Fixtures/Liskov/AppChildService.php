<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Liskov;

class AppChildService extends SimulatedVendorParent
{
    // Inherits execute() without a local docblock
    public function execute(int $code): bool
    {
        return true;
    }
}
