# Vendor & Path Filtering

TypePHP provides surgical control over which files are intercepted and type-checked. Using pattern specificity, you can whitelist specific vendor packages, blacklist single legacy files, and isolate your application from third-party vendor docblock errors.

> **Package Authors & Extensions:** Building a third-party package or framework integration and want to automatically whitelist your package directory without requiring users to edit `typephp.php`? See the [TypePHP Extensions](/advanced/extensions) guide.

---

## The Pattern Specificity Algorithm

When a file path matches both an `include` pattern and an `exclude` pattern, TypePHP determines the outcome by calculating **pattern specificity length** (`strlen($pattern)`):

**`Winning Pattern = max(Specificity Length)`**

* **Longer Pattern Wins:** A more specific pattern like `'vendor/acme/package/**'` (length 25) takes precedence over a broader glob like `'vendor/**'` (length 8).
* **Single File Override:** A specific file path like `'src/Legacy/UnsafeFile.php'` (length 25) takes precedence over a directory glob like `'src/**'` (length 6).
* **Tie-Breaker:** If pattern lengths are equal, `exclude` takes precedence to ensure application safety.

---

## Vendor Package Whitelisting

By default, `vendor/**` is listed in your `exclude` configuration to protect application performance and prevent vendor docblock bleed. 

To type-check a specific third-party vendor package, add its package path to your `include` configuration:

```php
// typephp.php
return [
    'include' => [
        'src/**',
        'app/**',
        'vendor/acme/domain-models/**', // Whitelist specific vendor package!
    ],

    'exclude' => [
        'vendor/**', // Exclude all other vendor packages
        'storage/**',
    ],
];
```

### How TypePHP Evaluates This

* **`vendor/acme/domain-models/src/User.php`**:
  * Matches `include`: `'vendor/acme/domain-models/**'` (Length: 29)
  * Matches `exclude`: `'vendor/**'` (Length: 8)
  * **Result:** `29 > 8` -> **Included and Type-Checked!**

* **`vendor/guzzlehttp/guzzle/src/Client.php`**:
  * Matches `include`: None
  * Matches `exclude`: `'vendor/**'` (Length: 8)
  * **Result:** **Excluded and Ignored!**

---

## Single-File Whitelisting & Blacklisting

Pattern specificity allows you to target or exclude individual PHP files with single-file precision:

### Single-File Whitelisting (Inside Excluded Folder)

Whitelist a single file inside `vendor/` without transforming the rest of the package:

```php
'include' => [
    'vendor/monolog/monolog/src/Monolog/Logger.php', // Whitelist single file!
],
'exclude' => [
    'vendor/**',
],
```

### Single-File Blacklisting (Inside Included Folder)

Blacklist a single legacy file inside an included application directory:

```php
'include' => [
    'src/**', // Include all src files by default
],
'exclude' => [
    'src/Legacy/UnsafeFile.php', // Blacklist this specific legacy file!
    'vendor/**',
],
```

> **Granular Code Suppression:** Need to skip type enforcement on a specific method or file without editing path configuration? See the [Ignore Annotations](/advanced/ignore-annotations) guide for `@typephp-ignore` and `@typephp-ignore-file` docblock tags.

---

## Vendor DocBlock Isolation (Preventing DocBlock Bleed)

Third-party vendor libraries often contain outdated, loose, or buggy PHPDoc annotations. If your application class extends a vendor class, inheriting those vendor docblocks could trigger unexpected `TypeError` exceptions in your code.

TypePHP prevents this via **Vendor Isolation**:

* When `ContractParser` resolves method or property inheritance, it checks `FileFilter::isFileExcluded()` on each parent class and interface file.
* If a parent class or interface is located inside an excluded folder (such as `/vendor/`), TypePHP **ignores its inherited docblocks**.
* This keeps your application completely insulated from third-party vendor docblock bugs.

---

## Automatic Non-PHP File Exclusion

Non-PHP files (`.json`, `.md`, `.css`, `.xml`, `.html`) are automatically rejected by `FileFilter` and `StreamWrapper` before glob evaluation occurs, ensuring zero performance overhead when non-PHP assets are loaded by your application.
