# Production Readiness & Strategy

This document outlines the stability status of TypePHP, production deployment strategies, and recommended safety guidelines.

---

## Pre-1.0 Stability Warning

> **Stability Warning:** TypePHP is currently in active pre-1.0 development and has not yet reached its stable `v1.0.0` release.
> 
> **Do NOT use TypePHP in high-stakes, mission-critical production applications yet.** 
> 
> TypePHP is currently recommended for:
> * Local development environments (require-dev)
> * Pest / PHPUnit test suites
> * CI/CD build pipelines
> * Staging and QA testing servers
> * Non-critical live applications and internal web tools

---

## Selective Whitelisting Strategy

When deploying TypePHP to non-critical live applications or staging environments, use **Selective Whitelisting** in `typephp.php` rather than type-checking your entire codebase.

Instead of including all application directories, target only specific domain modules:

```php
// typephp.php
return [
    'include' => [
        'app/Domain/Billing/**', // Whitelist specific domain logic
        'app/Services/Payment/**',
    ],

    'exclude' => [
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
    ],
];
```

### Why Selective Whitelisting Works

1. **Zero Overhead for Unincluded Code:** Files not listed in `include` pass directly to PHP's native C-engine with zero AST parsing, zero transformation, and zero validation overhead.
2. **Targeted Guard Rails:** Your core domain services protect themselves against bad input data without adding execution overhead to simple GET endpoints or rendering logic.

---

## Production Performance Optimization

When running TypePHP in live or staging environments, apply these three performance optimizations:

### 1. Enable Disk Caching (`cache => true`)

Ensure disk caching is enabled in `typephp.php`:

```php
'cache' => true,
```

When caching is enabled, TypePHP transforms each PHP file once and saves the pre-compiled output to disk (`typephp-cache/`). PHP's **OPCache** loads the transformed bytecode directly into RAM, meaning AST parsing runs **0 times** on subsequent HTTP requests.

### 2. Pre-Warm Cache During Deployment (`cache:warm`)

Run `cache:warm` (or `cache:rebuild`) in your deployment scripts before opening web traffic:

```bash
# In your deployment pipeline:
vendor/bin/typephp cache:rebuild
```

This pre-transforms all included PHP files on disk, ensuring the very first HTTP request receives instant $O(1)$ execution speed.

### 3. Disable Heavy Inline Variable Toggles

In live environments, you can disable local internal variable assignment checks while keeping strict function parameter and return boundaries active:

```php
'params'  => true, // Keep public function parameter contracts ON
'returns' => true, // Keep public function return contracts ON

'inline_vars' => [
    'properties' => true,
    'generics'   => true,
    'callables'  => true,
    'scalars'    => false, // Turn off inline scalar checks for maximum loop speed
    'arrays'     => false, // Turn off inline array checks for maximum loop speed
    'objects'    => true,
],
```

---

Here is the updated **Emergency Kill-Switches** section for `docs/production/production-readiness.md` with the callout note explaining the difference between the environment approach and config approach:

---

## Emergency Kill-Switches

If you ever need to disable TypePHP instantly in a live environment, you have two zero-downtime options:

### 1. Environment Variable Kill-Switch (`TYPEPHP_DISABLE`)

Set `TYPEPHP_DISABLE=true` in your server environment or `.env` file:

```bash
export TYPEPHP_DISABLE=true
```

This prevents `StreamWrapper` from registering during Composer autoloading.

### 2. Config Master Switch (`enabled => false`)

Set `'enabled' => false` in `typephp.php` or dynamically at runtime:

```php
TypePHP::setConfig(['enabled' => false]);
```

All runtime check methods immediately become instant no-ops and pass raw values through natively.

> **Key Difference Between Disabling Approaches:**
> * **Environment Level (`TYPEPHP_DISABLE=true`):** Evaluated during Composer autoloading (`vendor/autoload.php`). TypePHP never boots, and the `StreamWrapper` is never registered with PHP's Zend Engine.
> * **Config Level (`'enabled' => false`):** TypePHP boots normally, but `StreamWrapper` and `RuntimeTypeChecker` act as an instant pass-through, bypassing all type checks during execution.
