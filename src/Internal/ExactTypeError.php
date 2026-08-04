<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * Custom TypeError that allows overriding the reported file and line without Reflection.
 */
final class ExactTypeError extends \TypeError
{
    public function __construct(string $message, string $file, int $line)
    {
        parent::__construct($message);
        $this->file = $file;
        $this->line = $line;
    }
}
