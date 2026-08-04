<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use TypePHP\Resolver\TemplateManager;

/**
 * @internal
 */
final class ScopeCleaner
{
    public function __construct(private string $function)
    {
    }

    public function __destruct()
    {
        TemplateManager::popCallFrame($this->function);
    }
}
