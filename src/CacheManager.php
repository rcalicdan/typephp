<?php

declare(strict_types=1);

namespace TypePHP;

final class CacheManager
{
    /**
     * Returns the absolute path to the cache directory.
     */
    public static function getCacheDir(): string
    {
        $config = Config::get();

        return $config['cache_dir'] ?? (sys_get_temp_dir() . '/typephp-cache');
    }

    /**
     * Clears all cached transformed files from the cache directory.
     */
    public static function clear(): int
    {
        $cacheDir = self::getCacheDir();

        if (! is_dir($cacheDir)) {
            return 0;
        }

        $files = glob($cacheDir . '/*.php');
        if (! $files) {
            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }
}