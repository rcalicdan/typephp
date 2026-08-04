<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * Factory creating ErrorMessage value objects and preparing native TypeError instances with exact caller traces.
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
     * Prepares a native TypeError exception before throwing.
     * For parameter errors, it sets the file and line to accurately blame the caller site.
     */
    public static function prepareException(\TypeError $e): \TypeError
    {
        $message = $e->getMessage();
        $isParamError = ! str_contains($message, 'Return value') 
            && ! str_contains($message, 'Variable $') 
            && ! str_contains($message, 'Property ') 
            && ! str_contains($message, 'Return iterator') 
            && ! str_contains($message, 'Generator sent value');

        if ($isParamError) {
            $trace = $e->getTrace();
            $callerFrame = $trace[0] ?? [];

            if (isset($callerFrame['file'], $callerFrame['line'])) {
                $ref = new \ReflectionObject($e);

                if ($ref->hasProperty('file')) {
                    $propFile = $ref->getProperty('file');
                    $propFile->setValue($e, $callerFrame['file']);
                }

                if ($ref->hasProperty('line')) {
                    $propLine = $ref->getProperty('line');
                    $propLine->setValue($e, $callerFrame['line']);
                }
            }
        }

        return $e;
    }
}