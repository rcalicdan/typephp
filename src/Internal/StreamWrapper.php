<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class StreamWrapper
{
    public $context;

    private $handle;

    private static array $includePatterns = [];

    private static array $excludePatterns = [];

    private static string $baseDir = '';

    private static bool $isInitialized = false;

    private static bool $cacheEnabled = true;

    private static string $cacheDir = '';

    public static function register(array $config = []): void
    {
        if (! self::$isInitialized || ! empty($config)) {
            self::$baseDir = rtrim(str_replace('\\', '/', getcwd()), '/');

            $includes = $config['include'] ?? ['**'];
            $excludes = $config['exclude'] ?? ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

            self::$includePatterns = array_map([self::class, 'compileGlobToRegex'], $includes);
            self::$excludePatterns = array_map([self::class, 'compileGlobToRegex'], $excludes);

            self::$cacheEnabled = $config['cache'] ?? true;
            self::$cacheDir = CacheManager::getCacheDir();

            self::$isInitialized = true;
        }

        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_restore('file');
    }

    private static function compileGlobToRegex(string $glob): string
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
            $pattern = '^' . preg_quote(self::$baseDir . '/', '#') . $regex . '$';
        }

        return '#' . $pattern . '#i';
    }

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        self::unregister();
        $exists = file_exists($path);
        $resolvedPath = $exists ? realpath($path) : '';
        self::register();

        $isAppFile = false;

        if ($exists && str_ends_with($path, '.php') && $resolvedPath !== false) {
            $normalizedPath = str_replace('\\', '/', $resolvedPath);
            $libSrcDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));

            if (! str_starts_with($normalizedPath, $libSrcDir)) {
                $isExcluded = false;
                foreach (self::$excludePatterns as $pattern) {
                    if (preg_match($pattern, $normalizedPath)) {
                        $isExcluded = true;

                        break;
                    }
                }

                if (! $isExcluded) {
                    foreach (self::$includePatterns as $pattern) {
                        if (preg_match($pattern, $normalizedPath)) {
                            $isAppFile = true;

                            break;
                        }
                    }
                }
            }
        }

        if (! $isAppFile) {
            self::unregister();
            $this->handle = fopen($resolvedPath ?: $path, $mode);
            self::register();

            return $this->handle !== false;
        }

        self::unregister();

        if (! self::$cacheEnabled) {
            $source = file_get_contents($resolvedPath);
            $transformed = self::transformSource($source);

            $this->handle = fopen('php://memory', 'r+');
            fwrite($this->handle, $transformed);
            rewind($this->handle);

            self::register();

            return $this->handle !== false;
        }

        if (! is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0777, true);
        }

        $mtime = filemtime($resolvedPath);
        // BUMPED CACHE KEY TO v22_
        $cacheKey = hash('xxh128', 'v22_' . $resolvedPath . $mtime);
        $cachedFile = self::$cacheDir . "/{$cacheKey}.php";

        if (! file_exists($cachedFile)) {
            $source = file_get_contents($resolvedPath);
            $transformed = self::transformSource($source);
            file_put_contents($cachedFile, $transformed);
        }

        $this->handle = fopen($cachedFile, $mode);
        self::register();

        return $this->handle !== false;
    }

    private static function transformSource(string $source): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $oldStmts = $parser->parse($source);
        $oldTokens = $parser->getTokens();

        $traverser1 = new NodeTraverser();
        $traverser1->addVisitor(new CloningVisitor());
        $newStmts = $traverser1->traverse($oldStmts);

        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new ContractVisitor());
        $newStmts = $traverser2->traverse($newStmts);

        $printer = new Standard();
        $transformed = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        // Fixed Regex: Using [^}]* ensures it never backtracks past the immediate closing brace!
        $result = preg_replace_callback(
            '/if\s*\(\$__typephpErr\s*=\s*\\\\TypePHP\\\\Internal\\\\RuntimeTypeChecker::checkParams\(.*?\)\)\s*\{[^}]*\}\r?\n?\s*/s',
            function ($match) {
                return preg_replace('/\s+/', ' ', trim($match[0])) . ' ';
            },
            $transformed
        );

        return $result ?? $transformed;
    }

    public function stream_read($count)
    {
        return fread($this->handle, $count);
    }

    public function stream_eof()
    {
        return feof($this->handle);
    }

    public function stream_stat()
    {
        return fstat($this->handle);
    }

    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    public function stream_close()
    {
        fclose($this->handle);
    }

    public function url_stat($path, $flags)
    {
        self::unregister();
        $result = @stat($path);
        self::register();

        return $result;
    }
}
