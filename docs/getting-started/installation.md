# Installation

TypePHP requires **PHP 8.1 or higher**.

---

## Recommended Installation (Development & Testing)

TypePHP is primarily designed as a **development and testing dependency** to enforce strict runtime type safety during local development, Pest/PHPUnit test runs, and CI/CD build pipelines:

```bash
composer require --dev typephp/typephp
```

---

## Pre-1.0 Stability Warning & Production Usage

> **Pre-1.0 Stability Warning:** TypePHP is currently in active pre-1.0 development and has not yet reached its stable `v1.0.0` release.
> 
> **Do NOT use TypePHP in high-stakes, mission-critical production applications yet.**
> 
> TypePHP is strongly recommended for local development, Pest/PHPUnit test suites, and CI/CD build pipelines. If you choose to run TypePHP in non-critical live applications, staging servers, or internal web tools, install it as a main dependency, but be aware of its pre-1.0 status:

```bash
composer require typephp/typephp
```

---

## Initializing Configuration

Generate a default `typephp.php` configuration file in your project root directory:

```bash
vendor/bin/typephp config:init
```

---

## Executing Individual Scripts via CLI

To execute a standalone PHP script with native PHP execution and active TypePHP runtime enforcement:

```bash
vendor/bin/typephp index.php
```

> **Note:** `vendor/bin/typephp` runs your script using your system's native PHP engine while activating TypePHP contract enforcement on the target script and all required application files.

---

## Autoloading & Bootstrapping

TypePHP automatically integrates with Composer's autoloader via `src/bootstrap.php`. Whenever `vendor/autoload.php` is required in your application or test suite, TypePHP boots automatically:

```php
<?php

require 'vendor/autoload.php';

// TypePHP is booted and active
```

This means that if you run your application through an entry point like a web framework's `public/index.php` file where `require 'vendor/autoload.php';` is declared, TypePHP automatically transforms and type-checks any PHP files that are whitelisted in your `typephp.php` configuration.

---

## Disabling Auto-Boot

To prevent TypePHP from booting on `vendor/autoload.php` (such as during specialized static analysis or build tool execution), set the `TYPEPHP_DISABLE` environment variable or constant:

```bash
# Environment Variable
export TYPEPHP_DISABLE=true
```

Or in PHP code before autoloading:

```php
define('TYPEPHP_DISABLE', true);

require 'vendor/autoload.php';
```
