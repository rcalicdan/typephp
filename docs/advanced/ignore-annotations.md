# Ignore Annotations (`@typephp-ignore`)

TypePHP provides docblock suppression tags to skip type enforcement on legacy code, un-refactored methods, or performance-critical loops without requiring you to remove PHPDoc annotations.

---

## File-Level Suppression (`@typephp-ignore-file`)

Place `@typephp-ignore-file` (or `@typephp-disable-file`) in a docblock at the top of a PHP file to skip AST transformation and type-checking for the entire file:

```php
<?php

/**
 * @typephp-ignore-file
 */

declare(strict_types=1);

namespace App\Legacy;

class UnfactoredLegacyService
{
    /**
     * @param positive-int $id
     */
    public function process(int $id): void
    {
        // Type-checking is completely skipped for all code in this file!
    }
}
```

> **Technical Note & Coding Convention:** Under the hood, TypePHP scans the raw file contents for `@typephp-ignore-file` before performing AST transformations, meaning the tag will function regardless of its position in the file. However, you should always place `@typephp-ignore-file` at the very top of the file (right after `<?php`) as a clean coding convention.

---

## Function & Method Level Suppression (`@typephp-ignore`)

Add `@typephp-ignore` (or `@typephp-disable`) to a function or class method docblock to skip contract enforcement for that specific function while keeping all other methods in the same class 100% type-checked:

```php
namespace App\Services;

class UserService
{
    /**
     * Active Type Checking
     *
     * @param positive-int $id
     */
    public function findUser(int $id): array
    {
        return ['id' => $id];
    }

    /**
     * Ignored Method - Type-checking skipped for this method only!
     *
     * @typephp-ignore
     * @param positive-int $id
     */
    public function legacyImport(int $id): void
    {
        // ...
    }
}

$service = new UserService();

// Normal method still enforces positive-int
$service->findUser(-5);
// Throws: TypeError: UserService::findUser(): Argument $id must be of type positive-int

// Ignored method skips type-checking!
$service->legacyImport(-500); // Passes without error
```

---

## Property & Property Hook Suppression (`@typephp-ignore`)

Add `@typephp-ignore` to a class property or PHP 8.4 property hook docblock to skip property assignment and hook validation:

```php
class UserProfile
{
    /**
     * Normal Property - Validated on assignment
     *
     * @var positive-int
     */
    public int $id = 10;

    /**
     * Ignored Property - Type-checking skipped
     *
     * @typephp-ignore
     * @var positive-int
     */
    public int $unvalidatedId = 10;

    /**
     * Ignored Property Hook - Hook validation skipped
     *
     * @typephp-ignore
     * @var positive-int
     */
    public int $unvalidatedHook {
        get => $this->_val;
        set => $this->_val = $value;
    }

    public int $_val = 10;
}

$profile = new UserProfile();

// Ignored property assignment passes -500 without error!
$profile->unvalidatedId = -500;

// Ignored property hook assignment passes -500 without error!
$profile->unvalidatedHook = -500;
```

---


### Updated Section for `docs/advanced/ignore-annotations.md`

## Forcing Audits in CI/CD (`respect_ignore_tags => false`)

During automated CI/CD builds or security audits, you can force TypePHP to **bypass all ignore tags** and type-check every method and file containing `@typephp-ignore` without modifying source code.

Set `'respect_ignore_tags' => false` in your `typephp.php` configuration or dynamically in test setup:

```php
// In a CI/CD test setup or script:
TypePHP::setConfig(['respect_ignore_tags' => false]);

// All @typephp-ignore methods and files will now be strictly type-checked!
```

> **Path Exclusions vs. DocBlock Suppression:** 
> Setting `'respect_ignore_tags' => false` ONLY overrides DocBlock annotations (`@typephp-ignore` and `@typephp-ignore-file`).
> 
> Files or directories blacklisted in your `typephp.php` configuration (`'exclude' => ['vendor/**', 'src/Legacy/**']`) remain **unconditionally excluded**. Path-level exclusions in configuration are never overridden by `respect_ignore_tags`.

---

## Summary of Ignore Annotations

| Annotation Tag | Target Scope | Behavior |
| :--- | :--- | :--- |
| **`@typephp-ignore-file`**, **`@typephp-disable-file`** | File Header | Skips AST transformation for the entire file. |
| **`@typephp-ignore`**, **`@typephp-disable`** | Function / Method | Skips parameter and return contract injection for the target method. |
| **`@typephp-ignore`**, **`@typephp-disable`** | Property / Hook | Skips property assignment and hook validation. |

