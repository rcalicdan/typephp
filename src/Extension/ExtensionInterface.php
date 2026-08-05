<?php

declare(strict_types=1);

namespace TypePHP\Extension;

/**
 * Interface for third-party extensions providing automatic configuration overrides and custom rules.
 */
interface ExtensionInterface
{
    /**
     * Returns configuration array (include) to merge into TypePHP.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}
