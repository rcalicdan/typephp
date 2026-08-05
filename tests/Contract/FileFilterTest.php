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

    test('allows specific vendor package when included with a more specific pattern', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/my-company/whitelisted-package/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        $whitelistedPath = str_replace('\\', '/', getcwd() . '/vendor/my-company/whitelisted-package/src/Service.php');
        $otherVendorPath = str_replace('\\', '/', getcwd() . '/vendor/guzzlehttp/guzzle/src/Client.php');

        expect(FileFilter::isFileExcluded($whitelistedPath))->toBeFalse()
            ->and(FileFilter::isFileExcluded($otherVendorPath))->toBeTrue();

        Config::reset();
    });

    test('allows including or excluding single specific files', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/monolog/monolog/src/Monolog/Logger.php',
            ],
            'exclude' => [
                'src/Legacy/UnsafeFile.php',
                'vendor/**',
            ],
        ]);

        $normalSrc = str_replace('\\', '/', getcwd() . '/src/TypePHP.php');
        $excludedSingleFile = str_replace('\\', '/', getcwd() . '/src/Legacy/UnsafeFile.php');
        $includedSingleVendorFile = str_replace('\\', '/', getcwd() . '/vendor/monolog/monolog/src/Monolog/Logger.php');
        $otherVendorFile = str_replace('\\', '/', getcwd() . '/vendor/monolog/monolog/src/Monolog/Formatter.php');

        expect(FileFilter::isFileExcluded($normalSrc))->toBeFalse()
            ->and(FileFilter::isFileExcluded($excludedSingleFile))->toBeTrue()
            ->and(FileFilter::isFileExcluded($includedSingleVendorFile))->toBeFalse()
            ->and(FileFilter::isFileExcluded($otherVendorFile))->toBeTrue();

        Config::reset();
    });
});
