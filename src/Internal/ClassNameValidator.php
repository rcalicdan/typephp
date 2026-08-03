<?php

declare(strict_types=1);

namespace TypePHP\Internal;

final class ClassNameValidator
{
    /**
     * Validates whether a given value is a syntactically valid PHP class, interface, trait, or enum identifier.
     * Handles fully-qualified names with leading backslashes.
     * Returns false for non-strings, empty strings, complex PHPDoc strings like "Producer<Dog>", "array{id: int}", or unions.
     */
    public static function isValid(mixed $name): bool
    {
        if (! is_string($name) || $name === '') {
            return false;
        }

        $trimmed = ltrim($name, '\\');
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*$/', $trimmed) === 1;
    }
}
