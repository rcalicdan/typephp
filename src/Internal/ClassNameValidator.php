<?php

declare(strict_types=1);

namespace TypePHP\Internal;

final class ClassNameValidator
{
    /**
     * Validates whether a given string is a syntactically valid PHP class, interface, trait, or enum identifier.
     * Handles fully-qualified names with leading backslashes.
     * Returns false for complex PHPDoc strings like "Producer<Dog>", "array{id: int}", unions, or invalid identifiers.
     */
    public static function isValid(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $trimmed = ltrim($name, '\\');
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*$/', $trimmed) === 1;
    }
}