# Quick Start Guide

TypePHP enforces PHPDoc type contracts at runtime. Below is an overview of core features and code examples.

> **Recommended Workflow: PHPStan / Psalm / Mago / Phan + TypePHP**
> By design, TypePHP is a **runtime type enforcer, not a docblock linter or static analyzer**. For maximum execution performance, TypePHP gracefully ignores malformed docblock syntax and duplicate type alias declarations, focusing strictly on validating runtime data.
> 
> It is highly recommended to use any static analyzer alongside TypePHP:
> * **PHPStan / Psalm / Mago / Phan (Compile-Time):** Lints your PHPDoc syntax, validates complex intersection rules, and catches static type errors in your IDE before code executes.
> * **TypePHP (Runtime):** Enforces those PHPDoc contracts during actual execution, ensuring your application against invalid API payloads, database records, and dynamic runtime data or making sure the doctypes will not lie to you at runtime.

---

## Execution & Framework Entry Points

Because TypePHP automatically integrates with Composer's autoloader (`vendor/autoload.php`), you don't always need to use the custom CLI runner.

If your application executes through an explicit, standard entry point like a web framework's **`public/index.php`**, Laravel's **`artisan`** console, or test runners like **`vendor/bin/pest`** and **`phpunit`**, TypePHP boots naturally out of the box. 

Once booted, TypePHP transparently intercepts, transforms, and enforces types on any PHP file that is whitelisted in your `typephp.php` configuration file (`include` paths).

*(For standalone single-file scripts without an autoloader, you can still use `vendor/bin/typephp index.php` to run them with type checking enabled).*

---

## Namespace & Import Resolution

TypePHP is fully aware of your file's namespace context and `use` import statements. You can write your docblocks using short imported names, relative names, aliased imports, or Fully Qualified Class Names (FQCN), and TypePHP will resolve them perfectly at runtime:

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Billing as BillingService;

/**
 * @param User $user                       // Resolved via use import
 * @param BillingService\Invoice $invoice  // Resolved via aliased import
 * @param \DateTimeImmutable $date         // Resolved via FQCN
 */
function processPayment(object $user, object $invoice, object $date): void 
{
    // ...
}
```

---

## Parameter Contracts (`@param`)

Write standard PHPDoc annotations on function or method parameters:

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @param non-empty-string $username
 */
function processUser(int $id, string $username): void
{
    // ...
}

// Valid Call
processUser(42, 'Alice');

// Invalid Call (Passing negative integer)
processUser(-5, 'Alice');
// Throws: TypeError: processUser(): Argument $id must be of type positive-int, negative int (-5) given
```

---

## Return Contracts (`@return`)

TypePHP validates function return values before they are returned to the caller:

```php
/**
 * @return array{id: positive-int, status: 'active'|'pending'}
 */
function fetchUserData(int $id): array
{
    if ($id <= 0) {
        return ['id' => $id, 'status' => 'active']; // Invalid: $id is negative
    }

    return ['id' => $id, 'status' => 'active'];
}

fetchUserData(-10);
// Throws: TypeError: fetchUserData(): Return value['id'] must be of type positive-int
```

---

## Typed Arrays, Lists, and Shapes

Enforce strict structure on arrays, sequential lists, and key-value maps:

```php
/**
 * @param list<positive-int> $scores
 * @param array<string, non-empty-string> $headers
 */
function processBatch(array $scores, array $headers): void
{
    // ...
}

// Valid Call
processBatch([10, 20, 30], ['Authorization' => 'Bearer token']);

// Invalid Call (Associative array passed where sequential list was expected)
processBatch(['score' => 10], ['Authorization' => 'Bearer token']);
// Throws: TypeError: processBatch(): Argument $scores must be a list
```

---

## Inline Variable Validation (`@var`)

Validate local variable assignments inside function bodies:

```php
/** @var positive-int $age */
$age = 25; // Valid

$age = -10; 
// Throws: TypeError: Variable $age must be of type positive-int, negative int (-10) given
```

---

## Runtime Generics with `WeakMap`

TypePHP binds generic template types (`T`) directly to object instances:

```php
use TypePHP\Tests\Fixtures\Generics\Collection;
use App\Models\User;
use App\Models\Product;

/** @var Collection<User> $users */
$users = new Collection();

$users->add(new User('Alice')); // Valid

$users->add(new Product('SKU-100')); 
// Throws: TypeError: Argument $item (template T = User) must be of type User, Product given
```

---

## PHP 8.4 Property Hooks & Asymmetric Visibility

TypePHP validates incoming and returned values on PHP 8.4 Property Hooks:

```php
class UserProfile
{
    /** @var positive-int */
    public private(set) int $id = 10;

    /** @var non-empty-string */
    public string $username {
        get => $this->_username;
        set => $this->_username = trim($value);
    }

    private string $_username = 'Alice';
}

$profile = new UserProfile();
$profile->username = '   '; 
// Throws: TypeError: Property UserProfile::$username must be of type non-empty-string
```

---

## Suppressing Type Checks (`@typephp-ignore` & `@typephp-ignore-file`)

TypePHP provides annotations to skip type enforcement on legacy code or performance-critical sections without removing docblock types.

### Function & Method Level Suppression

Add `@typephp-ignore` to a function or class method docblock to skip type-checking for that specific function:

```php
/**
 * @typephp-ignore
 * @param positive-int $id
 */
function legacyProcess(int $id): void
{
    // TypePHP skips type enforcement for this specific function
}

legacyProcess(-500); // Passes without error
```

### File-Level Suppression

Place `@typephp-ignore-file` in a file-level docblock at the top of a file:

```php
<?php

/**
 * @typephp-ignore-file
 */

declare(strict_types=1);

namespace App\Legacy;

// All functions, methods, and properties in this file are skipped by TypePHP
```

> **Technical Note & Coding Convention:**
> Under the hood, TypePHP scans the raw file contents for `@typephp-ignore-file` before performing AST transformations, meaning the tag will function regardless of its position in the file. However, you should always place `@typephp-ignore-file` at the very top of the file (right after `<?php`) as a clean coding convention.
