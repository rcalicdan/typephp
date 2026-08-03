<?php

declare(strict_types=1);

namespace TypePHP\Internal;

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
}
