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
    | Set to false for an emergency kill-switch or zero-overhead benchmarking.
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

## Inline Variable Categories Reference (`inline_vars`)

How each `inline_vars` toggle maps to PHPDoc type annotations:

| Config Option | Covered PHPDoc Types | Examples |
| :--- | :--- | :--- |
| **`'scalars'`** | Primitive & Refined Scalars | `int`, `string`, `bool`, `positive-int`, `non-empty-string`, `truthy` |
| **`'objects'`** | Class Instances & Bare Class References | `User`, `stdClass`, `class-string`, `interface-string`, `enum-string` |
| **`'generics'`** | Template & Bound Types | `Collection<User>`, `Producer<T>`, `class-string<T>` |
| **` illegible 'arrays'`** | All Arrays, Shapes, & Lists | `array{id: int}`, `int[]`, `User[]`, `list<string>`, `array<string, int>` |
| **`'callables'`** | Callables & Closures | `callable`, `Closure`, `callable(int): string`, `static-closure` |
| **`'properties'`** | Class Property Writes | `$this->id = 1`, `UserProfile::$username = 'Alice'` |

### Important Notes on `inline_vars` Behavior

* **Inner Structural Types Are Always Validated:** Disabling `'scalars' => false` only turns off standalone scalar assignments (such as `/** @var positive-int $x */`). If `'arrays'` or `'generics'` is enabled, TypePHP **will still validate inner scalar constraints** inside array shapes (`array{id: positive-int}`), lists (`list<positive-int>`), or generic containers (`Collection<positive-int>`) to maintain structural type integrity.
* **Active Generic Instance Prebinding:** Enabling `'generics' => true` allows inline `@var` annotations on object instantiations (such as `/** @var Collection<User> $users */ $users = new Collection();`) to **actively prebind generic template parameters (`T = User`)** directly to that object instance in `WeakMap` memory. Every subsequent method call on that instance (`$users->add()`, `$users->get()`) will enforce `T = User`!

---

## Pattern Specificity Rules

If a file matches both an `include` rule and an `exclude` rule, TypePHP compares pattern lengths:

* **Specific Whitelist Wins:** `'vendor/my-org/package/**'` (length 25) takes precedence over `'vendor/**'` (length 8).
* **Single File Override:** `'src/LegacyFile.php'` (length 22) takes precedence over `'src/**'` (length 6).
* **Tie-Breaker:** If pattern lengths are equal, `exclude` takes precedence to ensure application safety.
