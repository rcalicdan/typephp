<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * Factory creating ErrorMessage value objects and manipulating native Caller traces.
 */
final class ErrorFactory
{
    /**
     * Creates an ErrorMessage value object containing formatted type failure details.
     */
    public static function createError(string $message): ErrorMessage
    {
        if (str_contains($message, 'Return value')) {
            $message = str_replace(['null given', ' given'], ['none returned', ' returned'], $message);
        }

        return new ErrorMessage($message);
    }

    /**
     * Prepares an exception before throwing.
     * For parameter errors, it extracts the caller's file and line to accurately blame the call site.
     */
    public static function prepareException(\TypeError $e): \TypeError
    {
        $message = $e->getMessage();
        $isParamError = ! str_contains($message, 'Return value')
            && ! str_contains($message, 'Variable $')
            && ! str_contains($message, 'Return iterator')
            && ! str_contains($message, 'Generator sent value');

        if ($isParamError) {
            $trace = $e->getTrace();
            // $trace[0] represents the caller frame that invoked the function containing the throw
            $callerFrame = $trace[0] ?? [];

            if (isset($callerFrame['file'], $callerFrame['line'])) {
                return new ExactTypeError($message, $callerFrame['file'], $callerFrame['line']);
            }
        }

        return $e;
    }
}
