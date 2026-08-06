# Quick Start Guide

TypePHP enforces PHPDoc type contracts at runtime. Below is an overview of core features.

---

## Parameter and Return Contracts

Write standard PHPDoc annotations on functions or class methods:

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @param non-empty-string $username
 * @return array{id: positive-int, username: non-empty-string, role: 'admin'|'user'}
 */
function createUser(int $id, string $username): array
{
    return [
        'id' => $id,
        'username' => $username,
        'role' => 'admin',
    ];
}

// Valid Call
createUser(42, 'Alice');

// Invalid Call (Passing negative integer)
createUser(-5, 'Alice');
// Throws: TypeError: createUser(): Argument $id must be of type positive-int, negative int (-5) given
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

## PHP 8.4 Property Hooks

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
