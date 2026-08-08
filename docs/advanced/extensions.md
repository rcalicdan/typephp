# Building Extensions

TypePHP provides an Extension System that allows third-party package authors and framework integrations to automatically register path whitelists so end-users can benefit from type checking without having to manually write many include file paths in `typephp.php`, which can be error-prone and hard to manage.

---

## Implementing `ExtensionInterface`

To create an extension, implement `TypePHP\Extension\ExtensionInterface` and return your package's whitelist include paths:

```php
<?php

declare(strict_types=1);

namespace Acme\SamplePackage;

use TypePHP\Extension\ExtensionInterface;

class TypePHPExtension implements ExtensionInterface
{
    /**
     * Returns configuration overrides provided by this extension.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'include' => [
                'vendor/acme/sample-package/**', // Whitelist package files automatically
            ],
        ];
    }
}
```

---

## Structural Safety ("Include-Only" Safeguard)

To protect end-user application stability and prevent third-party package conflicts, TypePHP enforces **Include-Only Authority** on extension configurations:

1. **Path Whitelisting:** Extensions can ONLY append paths to the global `include` array.
2. **Exclusion Protection:** Extensions are structurally forbidden from adding `exclude` rules. A third-party extension can never blacklist your application's `src/` directory or another package.
3. **Feature Flag Immunity:** Extensions cannot modify global feature flags (`enabled`, `cache`, `inline_vars`). The end-user's `typephp.php` holds ultimate authority.

---

## Registering Extensions in `typephp.php`

End-users explicitly register extension classes in their `typephp.php` configuration file:

```php
// typephp.php
return [
    /*
    |--------------------------------------------------------------------------
    | Registered Extensions
    |--------------------------------------------------------------------------
    | Explicitly list third-party extension classes.
    */
    'extensions' => [
        \Acme\SamplePackage\TypePHPExtension::class,
    ],

    'include' => [
        'src/**',
        'app/**',
        'tests/**',
    ],

    'exclude' => [
        'vendor/**',
        'storage/**',
    ],
];
```

---

## Future Expansion Roadmap

In current releases, the Extension System focuses strictly on path whitelisting. In future releases, the Extension System will be expanded to support:

* **Custom Type Validators:** Registering custom validator strategies for third-party classes or custom PHPDoc tags.
* **Custom Type Resolvers:** Resolving specialized domain-specific type identifiers at runtime.
* **Automated Installer Plugins:** Optional Composer plugins for automatic extension discovery.
