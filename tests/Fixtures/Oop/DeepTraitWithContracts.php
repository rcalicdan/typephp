<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

trait DeepTraitWithContracts
{
    /**
     * @param positive-int $level
     * @return non-empty-string
     */
    public function logMessage(int $level, string $msg): string
    {
        return "log_{$level}_{$msg}";
    }
}