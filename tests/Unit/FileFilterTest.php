<?php

declare(strict_types=1);

use TypePHP\Contract\FileFilter;
use TypePHP\Internal\Config;

describe('FileFilter Unit Tests', function () {
    test('returns false for null, empty, or false file paths', function () {
        expect(FileFilter::isFileExcluded(null))->toBeFalse()
            ->and(FileFilter::isFileExcluded(''))->toBeFalse();
    });

    test('excludes vendor directory paths automatically', function () {
        $vendorPath1 = 'C:/project/vendor/composer/autoload.php';
        $vendorPath2 = '/var/www/project/vendor/phpunit/phpunit/src/Framework.php';

        expect(FileFilter::isFileExcluded($vendorPath1))->toBeTrue()
            ->and(FileFilter::isFileExcluded($vendorPath2))->toBeTrue();
    });

    test('excludes storage and cache paths matching default config patterns', function () {
        Config::reset();

        $storagePath = str_replace('\\', '/', getcwd() . '/storage/framework/views/cache.php');
        $varPath = str_replace('\\', '/', getcwd() . '/var/cache/test.php');

        expect(FileFilter::isFileExcluded($storagePath))->toBeTrue()
            ->and(FileFilter::isFileExcluded($varPath))->toBeTrue();
    });

    test('allows application source and test files', function () {
        $srcPath = str_replace('\\', '/', getcwd() . '/src/TypePHP.php');
        $testPath = str_replace('\\', '/', getcwd() . '/tests/Feature/ParamContractsTest.php');

        expect(FileFilter::isFileExcluded($srcPath))->toBeFalse()
            ->and(FileFilter::isFileExcluded($testPath))->toBeFalse();
    });
});