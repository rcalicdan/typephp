<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

trait ExecutorTrait
{
    // No docblock on trait method!
    public function execute(int $code): string
    {
        if ($code === 999) {
            return ''; // Violates interface @return non-empty-string!
        }

        return "code_{$code}";
    }
}
