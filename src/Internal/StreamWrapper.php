<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use TypePHP\Resolver\SpecialTypeResolver;

/**
 * Custom stream wrapper intercepting 'file://' inclusions to perform on-the-fly AST transformations.
 *
 * @internal
 */
final class StreamWrapper implements StreamWrapperInterface
{
    /**
     * Context resource provided by PHP stream subsystem.
     *
     * @var resource|null
     */
    public $context;

    /**
     * Active file handle resource.
     *
     * @var resource|null
     */
    private $handle = null;

    /**
     * Active directory handle resource.
     *
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
     * Registers the custom StreamWrapper for the 'file://' protocol, merging optional config with Config::get().
     *
     * @param array<string, mixed> $config
     */
    public static function register(array $config = []): void
    {
        $resolvedConfig = array_replace_recursive(Config::get(), $config);

        if (! self::$isInitialized || count($config) > 0) {
            $cwd = getcwd();
            $base = $cwd !== false ? $cwd : '';
            self::$baseDir = rtrim(str_replace('\\', '/', $base), '/');

            /** @var array<int, string> $includes */
            $includes = is_array($resolvedConfig['include'] ?? null) ? $resolvedConfig['include'] : ['**'];

            /** @var array<int, string> $excludes */
            $excludes = is_array($resolvedConfig['exclude'] ?? null) ? $resolvedConfig['exclude'] : ['vendor/**', 'storage/**', 'var/**', 'cache/**'];

            self::$includePatterns = array_map([self::class, 'compileGlobToRegex'], $includes);
            self::$excludePatterns = array_map([self::class, 'compileGlobToRegex'], $excludes);

            self::$cacheEnabled = (bool) ($resolvedConfig['cache'] ?? true);
            self::$cacheDir = CacheManager::getCacheDir();

            self::$isInitialized = true;
        }

        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    /**
     * Restores PHP's native 'file://' stream wrapper protocol handler.
     */
    public static function unregister(): void
    {
        stream_wrapper_restore('file');
    }

    /**
     * Transforms PHP source code by parsing AST, extracting metadata, applying ContractVisitor, and formatting output.
     */
    public static function transformSource(string $source, string $filePath = ''): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $oldStmts = $parser->parse($source);
        if ($oldStmts === null) {
            return $source;
        }

        self::extractAndSeedFileMetadata($oldStmts, $filePath);

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

        $printer = new TypePHPPrinter();
        $transformed = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        // Critical: Remove the newline and indentation preceding any injected statement to preserve line counts.
        $transformed = preg_replace('/[ \t]*\r?\n[ \t]*\/\*__TYPEPHP_INJECTED__\*\//', ' /*__TYPEPHP_INJECTED__*/', $transformed) ?? $transformed;
        $transformed = str_replace('/*__TYPEPHP_INJECTED__*/', '', $transformed);

        return $transformed;
    }

    /**
     * Opens a file stream, intercepting application files for AST transformation.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::unregister();
        $exists = self::silent(fn () => file_exists($path));
        $resolvedPath = $exists ? realpath($path) : '';
        self::register();

        $isAppFile = $exists && ! self::isReadOnlyCall() && self::isApplicationFile($path, $resolvedPath);

        if (! $isAppFile || $resolvedPath === false) {
            self::unregister();
            $targetFile = ($resolvedPath !== false && $resolvedPath !== '') ? $resolvedPath : $path;

            /** @var resource|false $handle */
            $handle = self::silent(fn () => fopen($targetFile, $mode));

            $this->handle = $handle !== false ? $handle : null;
            self::register();

            return $this->handle !== null;
        }

        self::unregister();

        $success = self::$cacheEnabled
            ? $this->openCachedStream($resolvedPath, $mode)
            : $this->openMemoryStream($resolvedPath);

        self::register();

        return $success;
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
        /** @var array<int|string, int>|false $result */
        $result = self::silent(fn () => stat($path));
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
            $result = (bool) self::silent(fn () => touch($path, (int) $time, (int) $atime));
        } elseif ($option === STREAM_META_ACCESS) {
            /** @var int $mode */
            $mode = is_int($value) ? $value : 0777;
            $result = (bool) self::silent(fn () => chmod($path, $mode));
        }
        self::register();

        return $result;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        self::unregister();
        /** @var resource|false $dh */
        $dh = self::silent(fn () => opendir($path));
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
        $result = (bool) self::silent(fn () => mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE)));
        self::register();

        return $result;
    }

    public function rmdir(string $path, int $options): bool
    {
        self::unregister();
        $result = (bool) self::silent(fn () => rmdir($path));
        self::register();

        return $result;
    }

    public function unlink(string $path): bool
    {
        self::unregister();
        $result = (bool) self::silent(fn () => unlink($path));
        self::register();

        return $result;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        self::unregister();
        $result = (bool) self::silent(fn () => rename($pathFrom, $pathTo));
        self::register();

        return $result;
    }

    /**
     * Converts a glob pattern into an absolute regex pattern for path matching.
     */
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

    /**
     * Executes a callback while temporarily suppressing PHP error and warning handlers.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private static function silent(callable $callback): mixed
    {
        set_error_handler(fn () => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Determines if the current stream_open call is for reading file contents (e.g. by Collision, Pest, IDEs)
     * rather than PHP engine's require/include execution.
     */
    private static function isReadOnlyCall(): bool
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $frame) {
            $func = strtolower($frame['function'] ?? '');
            if (in_array($func, ['file_get_contents', 'file', 'readfile', 'highlight_file', 'show_source', 'token_get_all', 'file_exists'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether a target PHP file path should be intercepted and transformed based on include/exclude patterns.
     */
    private static function isApplicationFile(string $path, string|false $resolvedPath): bool
    {
        if (! str_ends_with($path, '.php') || $resolvedPath === false) {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        $parentDir = realpath(__DIR__ . '/..');
        $libSrcDir = $parentDir !== false ? str_replace('\\', '/', $parentDir) : '';

        if ($libSrcDir !== '' && str_starts_with($normalizedPath, $libSrcDir)) {
            return false;
        }

        foreach (self::$excludePatterns as $pattern) {
            if (preg_match($pattern, $normalizedPath) === 1) {
                return false;
            }
        }

        foreach (self::$includePatterns as $pattern) {
            if (preg_match($pattern, $normalizedPath) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Transforms and loads source code directly into RAM (php://memory).
     */
    private function openMemoryStream(string $resolvedPath): bool
    {
        $source = file_get_contents($resolvedPath);
        if ($source === false) {
            return false;
        }

        $transformed = self::transformSource($source, $resolvedPath);

        $memHandle = fopen('php://memory', 'r+');
        if ($memHandle !== false) {
            fwrite($memHandle, $transformed);
            rewind($memHandle);
            $this->handle = $memHandle;
        }

        return $this->handle !== null;
    }

    /**
     * Transforms and caches source code on disk before opening a file handle.
     */
    private function openCachedStream(string $resolvedPath, string $mode): bool
    {
        if (! is_dir(self::$cacheDir)) {
            self::silent(fn () => mkdir(self::$cacheDir, 0777, true));
        }

        $mtime = filemtime($resolvedPath);
        $mtimeStr = $mtime !== false ? (string) $mtime : '0';

        $cacheKey = hash('xxh128', 'v36_' . $resolvedPath . $mtimeStr);
        $cachedFile = self::$cacheDir . "/{$cacheKey}.php";

        if (! file_exists($cachedFile)) {
            $source = file_get_contents($resolvedPath);
            if ($source !== false) {
                $transformed = self::transformSource($source, $resolvedPath);
                file_put_contents($cachedFile, $transformed);
            }
        }

        $cacheHandle = fopen($cachedFile, $mode);
        $this->handle = $cacheHandle !== false ? $cacheHandle : null;

        return $this->handle !== null;
    }

    /**
     * Scans top-level AST statements for namespace and use import declarations to seed SpecialTypeResolver.
     *
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    private static function extractAndSeedFileMetadata(array $stmts, string $filePath): void
    {
        if ($filePath === '') {
            return;
        }

        $namespace = '';
        $imports = [];

        $nodesToScan = $stmts;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $namespace = $stmt->name !== null ? $stmt->name->toString() : '';
                $nodesToScan = $stmt->stmts;

                break;
            }
        }

        foreach ($nodesToScan as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
                if ($stmt->type !== \PhpParser\Node\Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }

                foreach ($stmt->uses as $use) {
                    $fqcn = $use->name->toString();
                    $alias = $use->getAlias()->toString();
                    $imports[$alias] = $fqcn;
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();

                foreach ($stmt->uses as $use) {
                    if ($use->type !== \PhpParser\Node\Stmt\Use_::TYPE_NORMAL && $use->type !== \PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN && $stmt->type !== \PhpParser\Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }

                    $fqcn = $prefix . '\\' . $use->name->toString();
                    $alias = $use->getAlias()->toString();
                    $imports[$alias] = $fqcn;
                }
            }
        }

        SpecialTypeResolver::seedFileMetadata($filePath, $namespace, $imports);
    }
}