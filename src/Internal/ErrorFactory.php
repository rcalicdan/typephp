<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * Factory creating ErrorMessage instances for call-site exception throwing.
 */
final class ErrorFactory
{
    /**
     * Creates an ErrorMessage value object containing formatted type failure details.
     */
    public static function createError(string $message): ErrorMessage
    {
        return new ErrorMessage($message);
    }
}
