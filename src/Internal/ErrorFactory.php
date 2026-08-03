<?php

declare(strict_types=1);

namespace TypePHP\Internal;

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

        if ($isReturnError) {
            $blameFrame = $trace[$callerFrameIndex - 1] ?? [];

            if (str_contains($message, 'null given')) {
                $message = str_replace('null given', 'none returned', $message);
            } else {
                $message = str_replace(' given', ' returned', $message);
            }
        } else {
            // Blame the call site (where the function was called)
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
