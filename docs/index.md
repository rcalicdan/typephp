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
      link: https://github.com/typephp/typephp

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

## Example Usage

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
    /** @var positive-int $limit */
    $limit = $options['count'];

    return [10, 20, 30];
}
```

---