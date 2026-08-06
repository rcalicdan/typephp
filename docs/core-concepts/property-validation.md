# Property Validation

TypePHP validates class property assignments and PHP 8.4 Property Hooks against declared `@var` annotations.

---

## Instance and Static Property Assignments

When you annotate a class property with `@var`, TypePHP intercepts assignments to that property and validates the value before the write occurs:

```php
<?php

declare(strict_types=1);

namespace App\Models;

class ConfiguredProperty
{
    /**
     * Instance Property Contract
     *
     * @var positive-int
     */
    public int $id = 10;

    /**
     * Static Property Contract
     *
     * @var non-empty-string
     */
    public static string $appTitle = 'TypePHP';

    public function updateId(int $newId): void
    {
        $this->id = $newId; // Validated against @var positive-int
    }

    public static function updateTitle(string $newTitle): void
    {
        self::$appTitle = $newTitle; // Validated against @var non-empty-string
    }
}

$config = new ConfiguredProperty();

// Valid Update
$config->updateId(42);

// Invalid Update ($newId is negative)
$config->updateId(-5);
// Throws: TypeError: Property App\Models\ConfiguredProperty::$id must be of type positive-int, negative int (-5) given

// Invalid Static Update ($newTitle is empty string)
ConfiguredProperty::updateTitle('');
// Throws: TypeError: Property App\Models\ConfiguredProperty::$appTitle must be of type non-empty-string
```

---

## PHP 8.1+ Readonly Properties and Classes

TypePHP validates PHP 8.1+ `readonly` properties and PHP 8.2+ `readonly` classes during initialization:

```php
class Order
{
    /**
     * @var positive-int
     */
    public readonly int $id;

    /**
     * @param non-empty-string $sku
     */
    public function __construct(
        int $id,
        public readonly string $sku
    ) {
        $this->id = $id; // Validated against @var positive-int!
    }
}

// Valid Instance
$order = new Order(100, 'SKU-500');

// Invalid Instance ($id is negative)
new Order(-50, 'SKU-500');
// Throws: TypeError: Property Order::$id must be of type positive-int
```

---

## PHP 8.4 Asymmetric Visibility (`public private(set)`)

TypePHP seamlessly supports PHP 8.4 Asymmetric Property Visibility. Type contracts on asymmetric properties are enforced when writes occur inside authorized class methods:

```php
class UserProfile
{
    /**
     * Public read, private set
     *
     * @var positive-int
     */
    public private(set) int $id = 10;

    public function setId(int $newId): void
    {
        $this->id = $newId; // TypePHP validates assignment inside the class!
    }
}

$profile = new UserProfile();
$profile->setId(-100);
// Throws: TypeError: Property UserProfile::$id must be of type positive-int
```

---

## PHP 8.4 Property Hooks (`get` & `set`)

TypePHP intercepts PHP 8.4 `get` and `set` property hooks, validating incoming values on `set` hooks and returned values on `get` hooks:

```php
class PropertyHookDemo
{
    /**
     * @var int[]
     */
    public array $shortGetNumbers {
        get => ['hello', 1]; // Returns invalid string 'hello' instead of int
    }

    /**
     * @var positive-int
     */
    public int $shortSetNumber {
        set => $this->_shortSetNumber = $value; // Validates incoming $value
    }

    public int $_shortSetNumber = 10;
}

$demo = new PropertyHookDemo();

// Invalid Get Hook Return
$value = $demo->shortGetNumbers;
// Throws: TypeError: Property PropertyHookDemo::$shortGetNumbers[0] must be of type int, string 'hello' given

// Invalid Set Hook Write
$demo->shortSetNumber = -5;
// Throws: TypeError: Property PropertyHookDemo::$shortSetNumber must be of type positive-int
```

---

## PHP 8.4 Interface Property Inheritance

If a class implements a PHP 8.4 Interface containing property hooks, the implementing class property inherits the interface property's `@var` docblock contract:

```php
interface HookedInterfaceProperty
{
    /**
     * @var positive-int
     */
    public int $interfaceProp { get; }
}

class HookedInterfaceImplementation implements HookedInterfaceProperty
{
    // Inherits @var positive-int from HookedInterfaceProperty interface!
    public int $interfaceProp {
        get => $this->_val;
    }

    public int $_val = 10;
}

$fixture = new HookedInterfaceImplementation();
$fixture->_val = -5;

$value = $fixture->interfaceProp;
// Throws: TypeError: Property HookedInterfaceImplementation::$interfaceProp must be of type positive-int
```

---

## Trait Property Inheritance

Properties declared inside Traits inherit their `@var` docblock contracts when used by a class:

```php
trait TraitWithProperties
{
    /**
     * @var positive-int
     */
    public int $traitInstanceProp = 10;

    public function setTraitProp(int $val): void
    {
        $this->traitInstanceProp = $val;
    }
}

class ClassUsingTrait
{
    use TraitWithProperties;
}

$app = new ClassUsingTrait();
$app->setTraitProp(-50);
// Throws: TypeError: Property ClassUsingTrait::$traitInstanceProp must be of type positive-int
```

---

## Generic Template Substitution in Properties

When a class property uses a class-level generic template (`@var array<int, T> $items`), TypePHP automatically substitutes `T` with the object instance's bound generic type:

```php
/**
 * @template T
 */
class GenericHookedCollection
{
    /**
     * @var array<int, T>
     */
    public array $items = [] {
        set => $this->items = $value;
    }
}

/** @var GenericHookedCollection<positive-int> $collection */
$collection = new GenericHookedCollection();

$collection->items = [10, -50];
// Throws: TypeError: Property GenericHookedCollection::$items[1] must be of type positive-int
```

---

## Configuration Control

Property assignment validation is enabled by default. You can toggle property validation in `typephp.php`:

```php
'inline_vars' => [
    'properties' => true, // Set to false to disable property assignment checks
],
```
