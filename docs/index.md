---
layout: home

hero:
  name: "TypePHP"
  text: "Transparent Runtime Type Enforcement"
  tagline: "Enforces DocBlock types at runtime transparently without introducing any new syntax. Written in pure PHP requiring absolutely zero C-extensions or FFI."
  actions:
    - theme: brand
      text: "Get Started →"
      link: /getting-started/installation
    - theme: alt
      text: "View on GitHub"
      link: https://github.com/typephp-php/typephp

features:
  - title: "Zero Production Overhead"
    details: "Install as a development dependency to enforce strict types during local testing and CI/CD pipelines, guaranteeing absolute zero performance cost in live production environments."
  - title: "True Runtime Generics"
    details: "Binds generic template types to specific object instances dynamically using native WeakMap memory tracking."
  - title: "Typed Arrays & Shapes"
    details: "Deeply validates sequential lists, typed class arrays, and strict associative array shape structures right out of the box."
  - title: "PHP 8.4 Support"
    details: "Native support for intercepting and validating PHP 8.4 Property Hooks (get/set) and Asymmetric Visibility (public private(set))."
---

## See It In Action

TypePHP operates entirely in user-land using native PHP stream wrappers and AST transformations. Because it is written in pure PHP and requires no C-extensions or FFI, you can drop it into any project effortlessly. It parses your standard PHPDoc annotations and enforces them the moment your code runs.

### True Runtime Generics
Define generic templates and TypePHP will track their state in memory per object instance:

```php
/**
 * @template T
 */
class Collection 
{
    /** @param T $item */
    public function add(mixed $item): void { /* ... */ }
}

// Prebind T = User to this specific object instance
/** @var Collection<User> $users */
$users = new Collection();

$users->add(new User('Alice')); // Valid

$users->add(new Product('SKU-100')); 
// TypeError: Argument $item (template T = User) must be of type User, Product given
```

### Array Shapes & Typed Arrays
Enforce strict associative array structures and collections of specific objects:

```php
/**
 * @param array{status: 'active'|'pending', tags: list<non-empty-string>} $options
 * @param User[] $collaborators
 */
function processBatch(array $options, array $collaborators): void 
{
    // ...
}

processBatch(
    options: ['status' => 'active', 'tags' => ['php', 'types']],
    collaborators: [new User(), new User()]
); // Valid

processBatch(
    options: ['status' => 'archived', 'tags' => ['php']], 
    collaborators: []
);
// TypeError: Argument $options['status'] must be of type ('active' | 'pending')
```

### Scalar Refinements & Function Boundaries
Catch invalid parameters before your function executes, and invalid return values before they leak out:

```php
/**
 * @param positive-int $id
 * @return non-empty-string
 */
function generateUserToken(int $id): string 
{
    return ""; // TypeError: Return value must be of type non-empty-string
}

generateUserToken(-5); 
// TypeError: Argument $id must be of type positive-int, negative int (-5) given
```

---

## Precise Stack Trace & Error Reporting

TypePHP injects single-line guard rails without shifting your source file line numbers. 

When an inline variable or type contract fails, framework error handlers and test runners (like Pest, PHPUnit, and Whoops) point **directly to the exact line number** where the invalid assignment or argument occurred in your application code:

```
  FAILED  Tests\SomeTest > test

  TypeError: Variable $typeArray[3] must be of type int, string '1' given

  at tests/SomeTest.php:7
      3| declare(strict_types=1);
      4| 
      5| test('test', function () {
      6|     /** @var array<int> */
  ➜   7|     $typeArray = [1, 2, 3, '1'];
      8| 
      9|     expect($typeArray)->toBeArray();
     10| });
```
