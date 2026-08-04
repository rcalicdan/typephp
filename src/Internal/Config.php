<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * Global configuration manager for loading and dynamically overriding settings.
 *
 * @internal
 */
final class Config
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $cachedConfig = null;

    /**
     * Loads and caches the global configuration from 'typephp.php' in the working directory.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        $cwd = getcwd();
        $configFile = $cwd !== false ? $cwd . '/typephp.php' : '';

        if ($configFile !== '' && file_exists($configFile)) {
            $loadedConfig = require $configFile;
            if (is_array($loadedConfig)) {
                /** @var array<string, mixed> $loadedConfig */
                return self::$cachedConfig = $loadedConfig;
            }
        }

        return self::$cachedConfig = [];
    }

    /**
     * Overrides the current configuration at runtime.
     *
     * @param array<string, mixed> $config
     */
    public static function set(array $config): void
    {
        /** @var array<string, mixed> $mergedConfig */
        $mergedConfig = array_replace_recursive(self::get(), $config);

        self::$cachedConfig = $mergedConfig;
    }

    /**
     * Resets the configuration cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$cachedConfig = null;
    }
}
