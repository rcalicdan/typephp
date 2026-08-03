<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class StreamWrapper implements StreamWrapperInterface
{
    /**
     * @var resource|null
     */
    public $context;

    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * @var resource|null
     */
    private $dirHandle = null;

    /**
     * @var array<int, string>
     */
    private static array $includePatterns = [];

    /**
     * @var array<int, string>
     */
    private static array $excludePatterns = [];

    private static string $baseDir = '';

    private static bool $isInitialized = false;

    private static bool $cacheEnabled = true;

    private static string $cacheDir = '';

    /**
     * @param array<string, mixed> $config
     */
    public static function register(array $config = []): void
    {
        if (! self::$isInitialized || count($config) > 0) {
            $cwd = getcwd();
            $base = $cwd !== false ? $cwd : '';
            self::$baseDir = rtrim(str_replace('\\', '/', $base), '/');

            /** @var array<int, string> $includes */
            $includes = is_array($config['include'] ?? null) ? $config['include'] : ['**'];

            /** @var array<int, string> $excludes */
            $excludes = is_array($config['exclude'] ?? null) ? $config['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

            self::$includePatterns = array_map([self::class, 'compileGlobToRegex'], $includes);
            self::$excludePatterns = array_map([self::class, 'compileGlobToRegex'], $excludes);

            self::$cacheEnabled = (bool) ($config['cache'] ?? true);
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

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::unregister();
        $exists = file_exists($path);
        $resolvedPath = $exists ? realpath($path) : '';
        self::register();

        $isAppFile = false;

        if ($exists && str_ends_with($path, '.php') && $resolvedPath !== false) {
            $normalizedPath = str_replace('\\', '/', $resolvedPath);
            $parentDir = realpath(__DIR__ . '/..');
            $libSrcDir = $parentDir !== false ? str_replace('\\', '/', $parentDir) : '';

            if ($libSrcDir !== '' && ! str_starts_with($normalizedPath, $libSrcDir)) {
                $isExcluded = false;
                foreach (self::$excludePatterns as $pattern) {
                    if (preg_match($pattern, $normalizedPath) === 1) {
                        $isExcluded = true;

                        break;
                    }
                }

                if (! $isExcluded) {
                    foreach (self::$includePatterns as $pattern) {
                        if (preg_match($pattern, $normalizedPath) === 1) {
                            $isAppFile = true;

                            break;
                        }
                    }
                }
            }
        }

        if (! $isAppFile || $resolvedPath === false) {
            self::unregister();
            $targetFile = ($resolvedPath !== false && $resolvedPath !== '') ? $resolvedPath : $path;
            $handle = fopen($targetFile, $mode);
            $this->handle = $handle !== false ? $handle : null;
            self::register();

            return $this->handle !== null;
        }

        self::unregister();

        if (! self::$cacheEnabled) {
            $source = file_get_contents($resolvedPath);
            if ($source === false) {
                self::register();

                return false;
            }

            $transformed = self::transformSource($source);

            $memHandle = fopen('php://memory', 'r+');
            if ($memHandle !== false) {
                fwrite($memHandle, $transformed);
                rewind($memHandle);
                $this->handle = $memHandle;
            }

            self::register();

            return $this->handle !== null;
        }

        if (! is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0777, true);
        }

        $mtime = filemtime($resolvedPath);
        $mtimeStr = $mtime !== false ? (string) $mtime : '0';

        $cacheKey = hash('xxh128', 'v29_' . $resolvedPath . $mtimeStr);
        $cachedFile = self::$cacheDir . "/{$cacheKey}.php";

        if (! file_exists($cachedFile)) {
            $source = file_get_contents($resolvedPath);
            if ($source !== false) {
                $transformed = self::transformSource($source);
                file_put_contents($cachedFile, $transformed);
            }
        }

        $cacheHandle = fopen($cachedFile, $mode);
        $this->handle = $cacheHandle !== false ? $cacheHandle : null;
        self::register();

        return $this->handle !== null;
    }

    private static function transformSource(string $source): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $oldStmts = $parser->parse($source);
        if ($oldStmts === null) {
            return $source;
        }

        $oldTokens = $parser->getTokens();

        $traverser1 = new NodeTraverser();
        $traverser1->addVisitor(new CloningVisitor());

        /** @var array<\PhpParser\Node\Stmt> $nodesToTraverse */
        $nodesToTraverse = $oldStmts;

        /** @var array<\PhpParser\Node\Stmt> $newStmts */
        $newStmts = $traverser1->traverse($nodesToTraverse);

        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new ContractVisitor());

        /** @var array<\PhpParser\Node\Stmt> $newStmts */
        $newStmts = $traverser2->traverse($newStmts);

        $printer = new Standard();
        $transformed = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        $result = preg_replace_callback(
            '/if\s*\(\$__typephpErr\s*=\s*\\\\TypePHP\\\\Internal\\\\RuntimeTypeChecker::checkParams\(.*?\)\)\s*\{[^}]*\}\r?\n?\s*/s',
            function (array $match): string {
                return preg_replace('/\s+/', ' ', trim($match[0])) . ' ';
            },
            $transformed
        );

        return $result ?? $transformed;
    }

    public function stream_read(int $count): string
    {
        if ($this->handle === null || $count <= 0) {
            return '';
        }

        $res = fread($this->handle, $count);

        return $res !== false ? $res : '';
    }

    public function stream_write(string $data): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $res = fwrite($this->handle, $data);

        return $res !== false ? $res : 0;
    }

    public function stream_lock(int $operation): bool
    {
        if ($this->handle === null) {
            return false;
        }

        if ($operation < 1 || $operation > 15) {
            return true;
        }

        // @phpstan-ignore-next-line
        return @flock($this->handle, $operation);
    }

    public function stream_flush(): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return fflush($this->handle);
    }

    public function stream_truncate(int $new_size): bool
    {
        if ($this->handle === null || $new_size < 0) {
            return false;
        }

        return ftruncate($this->handle, $new_size);
    }

    public function stream_eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        return feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        if ($this->handle === null) {
            return false;
        }

        return fstat($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::unregister();
        $result = @stat($path);
        self::register();

        return $result;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        self::unregister();
        $result = false;
        if ($option === STREAM_META_TOUCH) {
            /** @var array{0?: int, 1?: int} $valueArray */
            $valueArray = is_array($value) ? $value : [];
            $time = $valueArray[0] ?? time();
            $atime = $valueArray[1] ?? $time;
            $result = @touch($path, $time, $atime);
        } elseif ($option === STREAM_META_ACCESS) {
            /** @var int $mode */
            $mode = is_int($value) ? $value : 0777;
            $result = @chmod($path, $mode);
        }
        self::register();

        return $result;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        self::unregister();
        $dh = @opendir($path);
        $this->dirHandle = $dh !== false ? $dh : null;
        self::register();

        return $this->dirHandle !== null;
    }

    public function dir_readdir(): string|false
    {
        if ($this->dirHandle === null) {
            return false;
        }

        return readdir($this->dirHandle);
    }

    public function dir_rewinddir(): bool
    {
        if ($this->dirHandle === null) {
            return false;
        }

        rewinddir($this->dirHandle);

        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->dirHandle !== null) {
            closedir($this->dirHandle);
            $this->dirHandle = null;
        }

        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        self::unregister();
        $result = @mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE));
        self::register();

        return $result;
    }

    public function rmdir(string $path, int $options): bool
    {
        self::unregister();
        $result = @rmdir($path);
        self::register();

        return $result;
    }

    public function unlink(string $path): bool
    {
        self::unregister();
        $result = @unlink($path);
        self::register();

        return $result;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        self::unregister();
        $result = @rename($pathFrom, $pathTo);
        self::register();

        return $result;
    }
}
