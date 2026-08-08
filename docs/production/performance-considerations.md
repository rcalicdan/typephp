# Performance Considerations

TypePHP is engineered for sub-second CLI test runs and $O(1)$ memory lookups during web request execution. This document explains the internal performance architecture, OPCache interactions, JIT realities, and benchmarking guidelines.

---

## Performance Architecture Overview

TypePHP minimizes execution overhead through 4 core architectural optimizations:

```
[Incoming Data Check]
         │
         ├── 1. Type String AST Cache ($parsedTypeNodeCache) ──► O(1) Instant AST Lookup
         │
         ├── 2. Object Validation WeakMap ($validatedObjectCache) ──► O(1) Object Memoization
         │
         ├── 3. Reflection Hierarchy Cache (HierarchyResolver) ──► O(1) Class Tree Lookup
         │
         └── 4. OPCache Disk Cache (typephp-cache/) ──► 0.00ms Transformation Overhead
```

---

## Core In-Memory Optimizations

### 1. Type String AST Caching (`$parsedTypeNodeCache`)

When an inline variable assignment runs (`/** @var positive-int $age */`), TypePHP tokenizes and parses the string `'positive-int'` using PHPStan's `TypeParser` **only once per PHP process**.

On all subsequent assignments or loop iterations, TypePHP retrieves the pre-parsed `TypeNode` directly from static RAM in $O(1)$ constant time, completely eliminating lexer and parser overhead during execution.

### 2. Massive Array Validation Overhead ($O(N)$ Complexity)

Validating array shapes (`array{id: int}`), sequential lists (`list<T>`), or typed arrays (`User[]`) requires iterating every individual array element:

* **Small to Medium Arrays (10–500 items):** Validated in microseconds with negligible CPU impact.
* **Massive Datasets (5,000–50,000+ items):** Carrying $O(N)$ iteration complexity, validating massive arrays synchronously introduces **significant CPU performance overhead**.

> **Production Warning:** Synchronously validating massive, multi-thousand element array datasets at runtime is **NOT recommended in live production applications**. 
> 
> **Alternative Strategy:** For large datasets, use **Generators (`Generator<K, V>`)** to validate items lazily one-by-one as you stream them, or turn off inline array checking (`inline_vars.arrays => false`) on internal methods while maintaining strict function parameter boundaries.

### 3. Object Validation Memoization (`\WeakMap`)

When validating large collections or arrays of objects (such as `User[]` or `list<Producer<Dog>>`), re-validating identical object instances repeatedly is CPU-intensive.

`TypeValidatorRegistry` memoizes previously validated object instances against type signatures using PHP's native `WeakMap`. 
* **$O(1)$ Validation:** If an object instance has already been checked against `User`, subsequent checks on the same object return `true` instantly.
* **Zero Memory Leaks:** The moment an object instance is garbage-collected by PHP, its `WeakMap` cache entry is automatically deleted from RAM.

### 4. In-Memory Reflection Hierarchy Caching (`HierarchyResolver`)

Resolving complex class, interface, trait, and property hook inheritance trees requires Reflection calls. 

`HierarchyResolver` caches resolved `ReflectionClass` and `ReflectionMethod` inheritance trees in static RAM arrays (`$classHierarchyCache` and `$methodHierarchyCache`). If a class has 20 methods, its inheritance tree is inspected **exactly once**.

---

## OPCache and Web Server Execution

Understanding the difference between CLI test runs and production web server execution:

### CLI Test Execution (Pest & PHPUnit)

During CLI test runs, a single PHP process executes your test suite. TypePHP transforms and executes 380+ complex type-checking feature and unit tests in **~1.10 seconds** without requiring any special PHP flags or server extensions.

### Production Web Servers (PHP-FPM, FrankenPHP, Swoole, RoadRunner)

In production web servers, when `'cache' => true` is enabled in `typephp.php`:

1. **Warm Cache:** Pre-transforming files via `vendor/bin/typephp cache:warm` during deployment writes transformed PHP code to disk (`typephp-cache/`).
2. **Bytecode Compilation:** PHP's **OPCache** compiles the cached file **once into bytecode in RAM**.
3. **Execution:** On all subsequent HTTP requests, PHP executes the transformed bytecode directly from OPCache RAM at native C-level speed. AST parsing runs **0 times**.

---

## Real-World Benchmark Discovery: PHP 8 JIT Behavior

During real-world benchmarking of TypePHP's test suite, we discovered an important behavioral reality regarding PHP 8's JIT (Just-In-Time) compiler:

### Short-Lived CLI Test Runs (Pest / PHPUnit)

Running Pest with JIT enabled in CLI (`php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=tracing vendor/bin/pest`) **increased execution time from 1.10s to 2.36s (over 2x slower)** compared to standard PHP CLI execution.

**Why JIT is slower for CLI test runs:**
1. **Cold-Start Allocation Overhead:** Allocating a 128MB JIT shared memory buffer and initializing tracing on a process that finishes in ~1 second adds ~1.2 seconds of compilation overhead.
2. **Discarded Machine Code:** Because the CLI process exits immediately after 1 second, the compiled JIT machine code is discarded without ever being reused across subsequent requests.

> **Recommendation for CLI Testing:** Run local Pest and PHPUnit test suites with standard PHP CLI execution (without `opcache.enable_cli=1` or JIT enabled) for maximum sub-second test speed.

### Web Server Environments (PHP-FPM, FrankenPHP, Swoole)

It remains **unconfirmed** whether enabling OPCache alone or OPCache + JIT provides a net performance speedup in production web server environments. Because PHP 8's JIT compiler is primarily designed for CPU-intensive mathematical calculations rather than I/O, array, and reflection operations, OPCache RAM bytecode caching provides the majority of execution speedup for TypePHP.

> **Community Benchmark Call-to-Action:**
> We need your real-world feedback! If you benchmark TypePHP on staging or live application workloads (using tools like Blackfire, Xdebug, or ApacheBench), please share your performance benchmarks and feedback with the project on [GitHub Discussions](https://github.com/typephp/typephp/discussions)!

---

## Benchmarking Guidelines for Your Application

To measure the exact execution impact of TypePHP on your specific application:

### 1. Conducting a True A/B Performance Benchmark

To establish an exact baseline comparison between native PHP and TypePHP:

* **Web Application A/B Benchmark (PHP-FPM / Laravel / Symfony):**
  Set `TYPEPHP_DISABLE=true` in your `.env` or server environment to completely bypass TypePHP's stream wrapper during autoloading, establishing an exact baseline for native PHP web execution speed.
* **CLI Script A/B Benchmark:**
  Compare running `php script.php` (standard native PHP execution without TypePHP) against `vendor/bin/typephp script.php` (TypePHP active execution).

### 2. Warm Up the Cache First

Always run `cache:warm` before benchmarking so file transformation time is excluded from your request benchmarks:

```bash
vendor/bin/typephp cache:warm
```

### 3. Isolate Boundary vs. Internal Checks

If you want to measure the performance impact of internal variable assignments versus function boundaries, test different `typephp.php` configurations:

```php
// Strict Boundaries + Ultra-Fast Internal Loops
'params' => true,
'returns' => true,

'inline_vars' => [
    'properties' => true,
    'generics'   => true,
    'callables'  => true,
    'scalars'    => false, // Turn off inline scalar checks for maximum loop speed
    'arrays'     => false, // Turn off inline array checks for maximum loop speed
    'objects'    => true,
],
```

### 4. Profiling with Blackfire or Xdebug

When profiling TypePHP using Blackfire or Xdebug:
* Look at **`TypeValidatorRegistry::validate`** for execution time spent on type validation.
* Notice that **`ContractParser::parse`** and **`SpecialTypeResolver`** drop to near-zero CPU time after the first invocation due to static RAM caching!
