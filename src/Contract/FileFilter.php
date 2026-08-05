<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use TypePHP\Internal\Config;

/**
 * Checks file paths against vendor directories and user-configured exclude globs.
 */
final class FileFilter
{
    /**
     * Determines whether a given file path is excluded from contract inheritance (e.g., vendor code).
     */
    public static function isFileExcluded(?string $fileName): bool
    {
        if ($fileName === null || $fileName === false || $fileName === '') {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $fileName);

        // Fast hardcoded vendor check to protect against vendor docblock bleed
        if (str_contains($normalizedPath, '/vendor/')) {
            return true;
        }

        $config = Config::get();
        $excludes = is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

        $cwd = getcwd();
        $baseDir = $cwd !== false ? rtrim(str_replace('\\', '/', $cwd), '/') : '';

        foreach ($excludes as $pattern) {
            $glob = str_replace('\\', '/', trim($pattern));
            $isAbsolute = str_starts_with($glob, '/') || (bool) preg_match('#^[a-zA-Z]:/#', $glob);

            $regex = preg_quote($glob, '#');
            $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);

            if ($isAbsolute) {
                $regexPattern = '^' . $regex . '$';
            } elseif (str_starts_with($glob, '**')) {
                $regexPattern = '.*' . substr($regex, 4) . '$';
            } else {
                $regexPattern = '^' . preg_quote($baseDir . '/', '#') . $regex . '$';
            }

            if (preg_match('#' . $regexPattern . '#i', $normalizedPath) === 1) {
                return true;
            }
        }

        return false;
    }
}
