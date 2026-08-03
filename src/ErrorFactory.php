<?php

declare(strict_types=1);

namespace TypePHP;

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

        $callerFrame = $trace[$callerFrameIndex] ?? [];
        $file = $callerFrame['file'] ?? 'unknown';
        $line = $callerFrame['line'] ?? 0;

        $nativeMessage = \sprintf('%s, called in %s on line %d', $message, $file, $line);
        $e = new \TypeError($nativeMessage);

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
