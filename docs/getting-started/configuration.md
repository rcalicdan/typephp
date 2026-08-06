# Configuration

Generate a default `typephp.php` configuration file in your project root directory:

```bash
vendor/bin/typephp config:init
```

---

## Default Configuration Options

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Global Master Switch
    |--------------------------------------------------------------------------
    | Controls whether TypePHP enforces type checks at runtime.
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Function Boundary Contracts (@param & @return)
    |--------------------------------------------------------------------------
    | Enforces function and method parameter and return type contracts uniformly.
    */
    'params' => true,
    'returns' => true,

    /*
    |--------------------------------------------------------------------------
    | Respect Ignore Docblock Tags
    |--------------------------------------------------------------------------
    | Set to false in CI/CD runs to force type-checking on @typephp-ignore methods.
    */
    'respect_ignore_tags' => true,

    /*
    |--------------------------------------------------------------------------
    | Enable Caching
    |--------------------------------------------------------------------------
    | Pre-transforms and caches PHP files on disk for maximum speed.
    */
    'cache' => true,

    /*
    |--------------------------------------------------------------------------
    | Registered Extensions
    |--------------------------------------------------------------------------
    | Explicitly list third-party extension classes.
    */
    'extensions' => [
        // \Acme\Domain\TypePHPExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inline Variable Validation (@var $x = ...)
    |--------------------------------------------------------------------------
    | Fine-grained control over local variable assignment checks.
    */
    'inline_vars' => [
        'properties' => true,
        'generics'   => true,
        'callables'  => true,
        'scalars'    => true,
        'arrays'     => true,
        'objects'    => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Included Paths & Whitelisting
    |--------------------------------------------------------------------------
    | Globs or specific file paths that should be intercepted and type-checked.
    */
    'include' => [
        'src/**',
        'app/**',
        'internals/**',
        'tests/**',
        // 'vendor/my-org/my-package/**', // Whitelist a specific vendor package
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    | Globs or specific file paths that should be ignored by the type checker.
    */
    'exclude' => [
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
    ],
];
```

---

## Configuration Reference

### Global Master Switch (`enabled`)
The `enabled` flag acts as the master kill-switch for TypePHP. When set to `false`, the interceptor completely steps out of the way, and no runtime type checking is performed.

**Config vs. Environment Variables:** 
While you can hardcode this value in `typephp.php`, it is highly recommended to bind this to your environment variables (e.g., `'enabled' => env('TYPEPHP_ENABLED', true)`). This allows you to easily toggle TypePHP across different environments:
*   **Local/Testing:** Set to `true` to catch type errors during development.
*   **Production:** Set to `false` for zero overhead, or `true` if you require absolute type safety in your production application.

### Respect Ignore Tags (`respect_ignore_tags`)
Developers can bypass runtime checks on performance-critical loops or legacy methods by adding the `@typephp-ignore` tag to a docblock. 
By default (`true`), TypePHP honors these tags and skips checking those specific methods. 

However, if you set this to `false`, TypePHP will **ignore the ignore tags** and enforce type-checking universally. This is incredibly useful for **CI/CD pipelines** or comprehensive test suites where you want to verify total type safety across the entire application without developers' local optimizations bypassing the tests.

### Function Boundary Contracts (`params` & `returns`)
These options dictate whether TypePHP enforces the types defined in your method signatures and docblocks. 
*   `params`: Validates incoming arguments against `@param` tags.
*   `returns`: Validates outbound data against `@return` tags.

> **Why is there no fine-grained configuration for boundaries?**
> You might notice that `inline_vars` allows you to selectively disable specific type checks (like scalars or generics), but `params` and `returns` do not. **This is an intentional architectural decision.**
> 
> Function boundaries represent the **public contract** of your application. If a method claims to return an `array<int, User>`, that contract must be absolute. Allowing selective enforcement at boundaries (e.g., checking the `User` object but ignoring the `int` key) creates an unreliable, unpredictable API. 
> 
> Inline variables, on the other hand, represent **internal state**. We provide fine-grained controls for inline variables so developers can optimize internal loop performance (e.g., turning off heavy generic checks locally) without breaking the guarantees of the public API boundaries.

### Caching (`cache`)
When enabled, TypePHP stores the transformed, type-injected versions of your PHP files on disk. Subsequent executions bypass the AST parsing phase entirely, resulting in near-native PHP execution speeds.

### Extensions (`extensions`)
This array allows you to register custom type handlers or third-party TypePHP plugins. Provide the fully qualified class name (FQCN) of your extension to have it booted during TypePHP's initialization.

### Inline Variable Validation (`inline_vars`)
Unlike boundaries, local variable assignments (using `@var` docblocks) offer granular control. You can toggle specific types of runtime checks on or off. For instance, you may want to ensure `objects` are strictly typed but disable `generics` checks if you are iterating over massive arrays and need to squeeze out extra micro-optimizations.

---

## Path Resolution & Specificity Rules

The `include` and `exclude` arrays determine which files TypePHP should analyze. If a file matches both an `include` rule and an `exclude` rule, TypePHP resolves the conflict by comparing the character length of the patterns:

* **Specific Whitelist Wins:** `'vendor/my-org/package/**'` (length 25) takes precedence over `'vendor/**'` (length 8). This allows you to exclude an entire directory but whitelist a specific package inside it.
* **Single File Override:** `'src/LegacyFile.php'` (length 22) takes precedence over `'src/**'` (length 6).
* **Tie-Breaker:** If pattern lengths are exactly equal, `exclude` takes precedence by default to ensure application safety and prevent unintended parsing errors.