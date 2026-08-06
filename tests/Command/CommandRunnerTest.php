<?php

declare(strict_types=1);

namespace TypePHP\Tests\Command;

use TypePHP\Command\CommandRunner;

describe('CommandRunner Unit Tests', function () {
    test('routes help command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['help'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('USAGE')
        ;
    });

    test('routes cache:clear command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['cache:clear'], $stream, $stream);

        expect($exitCode)->toBe(0);
    });

    test('routes cache:warm command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['cache:warm'], $stream, $stream);

        expect($exitCode)->toBe(0);
    });

    test('routes cache:rebuild command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['cache:rebuild'], $stream, $stream);

        expect($exitCode)->toBe(0);
    });

    test('returns exit code 1 when target file does not exist', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['non_existent_script_123.php'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Error')
        ;
    });
});
