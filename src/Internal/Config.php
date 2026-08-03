<?php

declare(strict_types=1);

namespace TypePHP\Internal;

final class Config
{
    private static ?array $cachedConfig = null;

    /**
     * Loads and caches the global configuration from 'typephp.php' in the working directory.
     */
    public static function get(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        $configFile = getcwd() . '/typephp.php';

        if (file_exists($configFile)) {
            $loadedConfig = require $configFile;
            if (is_array($loadedConfig)) {
                return self::$cachedConfig = $loadedConfig;
            }
        }

        return self::$cachedConfig = [];
    }
}
