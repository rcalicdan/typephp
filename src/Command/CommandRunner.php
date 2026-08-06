<?php

declare(strict_types=1);

namespace TypePHP\Command;

final class CommandRunner
{
    /**
     * Parses CLI arguments and routes execution to the corresponding command class.
     *
     * @param array<int, string> $args
     * @param resource $outputStream
     * @param resource $errorStream
     */
    public static function run(array $args, $outputStream = STDOUT, $errorStream = STDERR): int
    {
        $showHelp = in_array('help', $args, true) || in_array('typephp:help', $args, true) || in_array('--help', $args, true) || in_array('-h', $args, true);

        if ($showHelp || empty($args)) {
            return (new HelpCommand())->execute($args, $outputStream, $errorStream);
        }

        if (in_array('config:init', $args, true) || in_array('init', $args, true)) {
            return (new ConfigInitCommand())->execute($args, $outputStream, $errorStream);
        }

        if (in_array('cache:rebuild', $args, true)) {
            return (new CacheRebuildCommand())->execute($args, $outputStream, $errorStream);
        }

        if (in_array('cache:clear', $args, true)) {
            return (new CacheClearCommand())->execute($args, $outputStream, $errorStream);
        }

        if (in_array('cache:warm', $args, true)) {
            return (new CacheWarmCommand())->execute($args, $outputStream, $errorStream);
        }

        return (new RunCommand())->execute($args, $outputStream, $errorStream);
    }
}