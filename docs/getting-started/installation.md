# Installation

TypePHP requires **PHP 8.1 or higher**.

## Installation via Composer

Install TypePHP in your project using Composer:

```bash
composer require typephp/typephp
```

## Initializing Configuration

Generate a default `typephp.php` configuration file in your project root directory:

```bash
vendor/bin/typephp config:init
```

---

## Bootstrapping

TypePHP hooks into Composer's autoloader via `src/bootstrap.php`. Whenever `vendor/autoload.php` is required, TypePHP initializes automatically:

```php
<?php

require 'vendor/autoload.php';

// TypePHP is booted and active
```

---

## CLI Command Runner

TypePHP provides a binary (`vendor/bin/typephp`) for running scripts and managing cache:

```bash
# Generate default configuration file
vendor/bin/typephp config:init

# Execute a script with TypePHP enabled
vendor/bin/typephp index.php

# Clear transformed disk cache
vendor/bin/typephp cache:clear

# Pre-transform and warm up cache for deployment
vendor/bin/typephp cache:warm

# Clear and rebuild cache in one command
vendor/bin/typephp cache:rebuild
```

---

## Disabling Auto-Boot

To prevent TypePHP from booting on `vendor/autoload.php` (such as during specialized build tool execution), define the `TYPEPHP_DISABLE` environment variable or constant:

```bash
# Environment Variable
export TYPEPHP_DISABLE=true
```

Or in PHP code before autoloading:

```php
define('TYPEPHP_DISABLE', true);

require 'vendor/autoload.php';
```