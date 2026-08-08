---
layout: home

hero:
  name: "TypePHP"
  text: "Runtime Type Enforcement for PHP"
  tagline: "Enforces PHPDoc generics, array shapes, integer ranges, and complex type contracts at runtime in development and production environments."
  actions:
    - theme: brand
      text: Get Started →
      link: /getting-started/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/typephp-php/typephp

features:
  - title: Zero-Cost Performance
    details: Pre-transforms AST code and caches files on disk. Runs at native OPCache RAM speed with constant O(1) memory lookup.
  - title: True Runtime Generics
    details: Prebinds template types (Collection<User>) to object instances using WeakMap memory tracking.
  - title: Production Ready
    details: Selective path whitelisting allows type-checking mission-critical domain logic in production with zero risk.
  - title: PHP 8.4 Support
    details: Native support for PHP 8.4 Property Hooks (get/set) and Asymmetric Visibility (public private(set)).
---

## Real-World Example

```php
use App\Models\User;
use TypePHP\Tests\Fixtures\Generics\Collection;

/**
 * Enforce parameter and return contracts directly in PHPDoc annotations
 *
 * @param Collection<User> $users
 * @param array{status: 'active'|'pending', count: positive-int} $options
 * @return list<positive-int>
 */
function processUserBatch(Collection $users, array $options): array
{
    /** @var array<int> $typeArray */
    $typeArray = [1, 2, 3, '1'];

    return [10, 20, 30];
}
```

---

## Precise Stack Trace & Error Reporting

TypePHP injects single-line guard rails without shifting your source file line numbers. 

When a type contract fails, framework error handlers and test runners (like Pest, PHPUnit, and Whoops) point **directly to the exact line number** where the invalid assignment or argument occurred:

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
