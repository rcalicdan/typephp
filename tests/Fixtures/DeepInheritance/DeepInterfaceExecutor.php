<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\DeepInheritance;

class DeepInterfaceExecutor implements DeepChildInterface
{
    public function executeDeep(int $code): bool
    {
        return true;
    }
}
