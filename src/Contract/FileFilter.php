<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use TypePHP\Internal\Config;

/**
 * Checks file paths against vendor directories and user-configured include/exclude globs.
 */
final class FileFilter
{
    /**
     * Determines whether a given file path is excluded from contract inheritance.
     * Uses pattern specificity to allow whitelisting specific vendor directories.
     */
    public static function isFileExcluded(?string $fileName): bool
    {
        if ($fileName === null || $fileName === false || $fileName === '') {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $fileName);

        $config = Config::get();
        $includes = is_array($config['include'] ?? null) ? $config['include'] : ['**'];
        $excludes = is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

        $cwd = getcwd();
        $baseDir = $cwd !== false ? rtrim(str_replace('\\', '/', $cwd), '/') : '';

        $longestIncludeMatch = 0;
        foreach ($includes as $pattern) {
            $regex = self::compileGlobToRegex((string) $pattern, $baseDir);
            if (preg_match($regex, $normalizedPath) === 1) {
                $longestIncludeMatch = max($longestIncludeMatch, strlen(trim((string) $pattern)));
            }
        }

        $longestExcludeMatch = 0;
        foreach ($excludes as $pattern) {
            $regex = self::compileGlobToRegex((string) $pattern, $baseDir);
            if (preg_match($regex, $normalizedPath) === 1) {
                $longestExcludeMatch = max($longestExcludeMatch, strlen(trim((string) $pattern)));
            }
        }

        return $longestExcludeMatch > $longestIncludeMatch;
    }

    /**
     * Converts a glob pattern into an absolute regex pattern.
     */
    private static function compileGlobToRegex(string $glob, string $baseDir): string
    {
        $glob = str_replace('\\', '/', trim($glob));
        $isAbsolute = str_starts_with($glob, '/') || (bool) preg_match('#^[a-zA-Z]:/#', $glob);

        $regex = preg_quote($glob, '#');
        $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);

        if ($isAbsolute) {
            $pattern = '^' . $regex . '$';
        } elseif (str_starts_with($glob, '**')) {
            $pattern = '.*' . substr($regex, 4) . '$';
        } else {
            $pattern = '(^' . preg_quote($baseDir . '/', '#') . '|^.*\/)' . $regex . '$';
        }

        return '#' . $pattern . '#i';
    }
}