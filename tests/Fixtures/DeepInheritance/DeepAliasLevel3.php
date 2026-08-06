<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\DeepInheritance;

/**
 * @phpstan-import-type DeepShape from DeepAliasLevel2 as LocalDeepShape
 */
class DeepAliasLevel3
{
    /**
     * @param LocalDeepShape $payload
     */
    public function processShape(array $payload): bool
    {
        return true;
    }
}
