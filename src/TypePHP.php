<?php

declare(strict_types=1);

namespace TypePHP;

use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;

final class TypePHP
{
    /**
     * Boots TypePHP and registers the custom StreamWrapper protocol.
     */
    public static function boot(): void
    {
        StreamWrapper::register(Config::get());
    }

    /**
     * Returns the current resolved global configuration settings.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return Config::get();
    }

    /**
     * Dynamically overrides configuration settings at runtime.
     * Useful for test environments and custom setup scripts.
     *
     * @param array<string, mixed> $config
     */
    public static function setConfig(array $config): void
    {
        Config::set($config);
    }

    /**
     * Resets the configuration cache back to typephp.php defaults.
     * Useful for test isolation between test runs.
     */
    public static function resetConfig(): void
    {
        Config::reset();
    }
}
