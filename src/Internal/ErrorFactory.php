<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal
 */
final class ErrorFactory
{
    public static function createError(string $message): \TypeError
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        $callerFrameIndex = 0;
        foreach ($trace as $i => $frame) {
            $class = $frame['class'] ?? '';
            if (! str_starts_with($class, 'TypePHP\\')) {
                $callerFrameIndex = $i;

                break;
            }
        }

        $isReturnError = str_contains($message, 'Return value');
        $isVariableError = str_contains($message, 'Variable $');
        $isIteratorError = str_contains($message, 'Return iterator') || str_contains($message, 'Generator sent value');

        if ($isReturnError || $isVariableError || $isIteratorError) {
            // Blame the frame that called INTO TypePHP (the assignment or return statement)
            $blameFrame = $trace[max(0, $callerFrameIndex - 1)] ?? [];

            if ($isReturnError) {
                if (str_contains($message, 'null given')) {
                    $message = str_replace('null given', 'none returned', $message);
                } else {
                    $message = str_replace(' given', ' returned', $message);
                }
            }
        } else {
            // Blame the frame outside of TypePHP (the caller of the function)
            $blameFrame = $trace[$callerFrameIndex] ?? [];
        }

        $file = $blameFrame['file'] ?? 'unknown';
        $line = $blameFrame['line'] ?? 0;

        $e = new \TypeError($message);

        $ref = new \ReflectionObject($e);
        $properties = [
            'file' => $file,
            'line' => $line,
            'trace' => \array_slice($trace, $callerFrameIndex),
        ];

        foreach ($properties as $propName => $value) {
            if ($ref->hasProperty($propName)) {
                $prop = $ref->getProperty($propName);
                $prop->setValue($e, $value);
            }
        }

        return $e;
    }
}
