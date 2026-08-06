# Function Contracts

Functions and methods form the public boundaries of your software modules. TypePHP enforces `@param` and `@return` annotations directly at function entry and exit points.

---

## Parameter Contracts (`@param`)

When you declare `@param` annotations on a function or class method, TypePHP validates all incoming arguments before entering the function body:

> **Suppressing Function Contracts:** Need to skip type-checking on a legacy function or method? Add `@typephp-ignore` to its docblock. See [Ignore Annotations](/advanced/ignore-annotations) for full details.

```php
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @param non-empty-string $username
 * @param 'admin'|'editor'|'viewer' $role
 */
function registerUser(int $id, string $username, string $role): void
{
    // Executed only if all arguments pass validation
}

// Valid Call
registerUser(100, 'Alice', 'admin');

// Invalid Call (Passing negative integer)
registerUser(-5, 'Alice', 'admin');
// Throws: TypeError: registerUser(): Argument $id must be of type positive-int, negative int (-5) given
```

> **Execution Order Note:** Native PHP type hints (e.g., `int $id`, `string $username`) are evaluated by PHP's C-engine *before* function execution begins. TypePHP's extended PHPDoc contracts (e.g., `positive-int`, `non-empty-string`) execute at the very start of the function/method body. If a native type hint fails, PHP throws its native `TypeError` before TypePHP's guard rails run.

---

## Class Methods (Instance & Static)

All parameter and return contract rules apply identically to **instance methods** (`public`, `protected`, `private`) and **static methods**:

```php
class UserService
{
    /**
     * Instance Method Contract
     *
     * @param positive-int $id
     * @return array{id: positive-int, name: non-empty-string}
     */
    public function findUser(int $id): array
    {
        return ['id' => $id, 'name' => 'Alice'];
    }

    /**
     * Static Method Contract
     *
     * @param non-empty-string $role
     * @return list<positive-int>
     */
    public static function getRoleIds(string $role): array
    {
        return [10, 20, 30];
    }
}

$service = new UserService();

// Invalid Instance Method Call ($id is negative)
$service->findUser(-10);
// Throws: TypeError: UserService::findUser(): Argument $id must be of type positive-int

// Invalid Static Method Call ($role is empty string)
UserService::getRoleIds('');
// Throws: TypeError: UserService::getRoleIds(): Argument $role must be of type non-empty-string
```

---

## Class Constructors (`__construct`)

TypePHP fully validates class constructor arguments, supporting both standard constructors and **Constructor Property Promotion** (PHP 8.0+).

### Promoted Properties (PHP 8.0+)

Annotate promoted properties in the constructor's docblock using standard `@param` tags:

```php
class Order
{
    /**
     * @param positive-int $id
     * @param non-empty-string $sku
     * @param int<1, 100> $quantity
     */
    public function __construct(
        public int $id,
        public string $sku,
        public int $quantity
    ) {}
}

// Valid Instance
new Order(1, 'SKU-99', 5);

// Invalid Instance ($id is negative)
new Order(-1, 'SKU-99', 5);
// Throws: TypeError: Order::__construct(): Argument $id must be of type positive-int
```

### Property `@var` Fallback for Un-Annotated Constructors

If a constructor parameter is un-annotated (or lacks a `@param` tag), TypePHP automatically inspects the corresponding class property's `@var` docblock to infer the parameter contract:

```php
class User
{
    /**
     * @var string[]
     */
    public array $roles;

    // Un-annotated constructor parameter inherits contract from $roles property docblock!
    public function __construct(array $roles)
    {
        $this->roles = $roles;
    }
}

// Invalid Instance (element 1 is an integer)
new User(['admin', 12345]);
// Throws: TypeError: User::__construct(): Argument $roles[1] must be of type string, int (12345) given
```

---

## Return Contracts (`@return`)

TypePHP validates `return` statements before values are returned to the caller:

```php
/**
 * @return array{id: positive-int, status: 'active'|'pending'}
 */
function getUserStatus(int $id): array
{
    if ($id <= 0) {
        return ['id' => $id, 'status' => 'active']; // Invalid: $id is negative
    }

    return ['id' => $id, 'status' => 'active'];
}

getUserStatus(-10);
// Throws: TypeError: getUserStatus(): Return value['id'] must be of type positive-int
```

> **PHPStan and Psalm Compatibility:** TypePHP also recognizes `@phpstan-param`, `@phpstan-return`, `@psalm-param`, and `@psalm-return` annotations.

---

## Variadic Parameter Contracts

When a function or method accepts variadic arguments (`...$items`), TypePHP validates every element passed in the variadic argument list:

```php
/**
 * @param positive-int ...$ids
 */
function deleteUsers(int ...$ids): void
{
    // ...
}

// Valid Call
deleteUsers(10, 20, 30);

// Invalid Call (3rd variadic item violates positive-int)
deleteUsers(10, 20, -5);
// Throws: TypeError: deleteUsers(): Argument $ids[2] must be of type positive-int
```

---

## Fluent `$this` Identity Returns

For fluent builder or service classes annotated with `@return $this`, TypePHP verifies strict object identity (`$result === $this`), preventing accidental instantiation of new instances:

```php
class UserBuilder
{
    private string $name = '';

    /**
     * @return $this
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this; // Valid: Strict $this identity
    }

    /**
     * @return $this
     */
    public function cloneSelf(): self
    {
        return new self(); // Invalid: New instance returned instead of $this
    }
}

$builder = new UserBuilder();
$builder->cloneSelf();
// Throws: TypeError: UserBuilder::cloneSelf(): Return value must be $this instance
```

---

## Conditional Return Types

TypePHP supports parameter-based conditional return types (`@return ($param is true ? TypeA : TypeB)`):

```php
/**
 * @param bool $asInt
 * @param mixed $value
 * @return ($asInt is true ? positive-int : non-empty-string)
 */
function formatValue(bool $asInt, mixed $value): mixed
{
    return $value;
}

// Valid Calls
formatValue(true, 42);       // Evaluates return type as positive-int
formatValue(false, 'hello'); // Evaluates return type as non-empty-string

// Invalid Call
formatValue(true, 'not_an_int');
// Throws: TypeError: formatValue(): Return value must be of type positive-int
```
