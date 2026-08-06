# Installation

TypePHP requires **PHP 8.1 or higher**.

---

## Recommended Installation (Development & Testing)

TypePHP is primarily designed as a **development and testing dependency** to enforce strict runtime type safety during local development, Pest/PHPUnit test runs, and CI/CD build pipelines:

```bash
composer require --dev typephp/typephp
```

---

## Production Installation (Optional & Advanced)

If you intend to use TypePHP in production to selectively enforce type contracts on mission-critical domain logic, payment gateways, or security boundaries, install it as a main dependency:

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

This means that if you run your application that uses entry point like web frameworks via public index.php file where the `require 'vendor/autoload';` is declared. TypePHP can now transform and typecheck a php file that is included in your configuration.

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
