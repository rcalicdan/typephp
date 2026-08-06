<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\DeepInheritance;

class DeepLevel4Executor extends DeepLevel3
{
    public function processDeep(int $id): string
    {
        if ($id === 999) {
            return ''; // Violates Level 1 @return non-empty-string!
        }

        return "deep_item_{$id}";
    }
}
