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

## Pattern Specificity Rules

If a file matches both an `include` rule and an `exclude` rule, TypePHP compares pattern lengths:

* **Specific Whitelist Wins:** `'vendor/my-org/package/**'` (length 25) takes precedence over `'vendor/**'` (length 8).
* **Single File Override:** `'src/LegacyFile.php'` (length 22) takes precedence over `'src/**'` (length 6).
* **Tie-Breaker:** If pattern lengths are equal, `exclude` takes precedence to ensure application safety.
