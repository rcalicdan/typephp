<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal
 */
final class CacheManager
{
    /**
     * Returns the absolute path to the cache directory.
     */
    public static function getCacheDir(): string
    {
        $config = Config::get();
        $dir = $config['cache_dir'] ?? null;

        return \is_string($dir) ? $dir : (sys_get_temp_dir() . '/typephp-cache');
    }

    /**
     * Clears all cached transformed files from the cache directory.
     */
    public static function clear(): int
    {
        $wasRegistered = StreamWrapper::isRegistered();
        StreamWrapper::unregister();

        $cacheDir = self::getCacheDir();

        if (! is_dir($cacheDir)) {
            if ($wasRegistered) {
                StreamWrapper::register();
            }

            return 0;
        }

        $files = glob($cacheDir . '/*.php');
        if ($files === false || count($files) === 0) {
            if ($wasRegistered) {
                StreamWrapper::register();
            }

            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }

        if ($wasRegistered) {
            StreamWrapper::register();
        }

        return $count;
    }
}
