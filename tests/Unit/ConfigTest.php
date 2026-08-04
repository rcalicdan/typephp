<?php

declare(strict_types=1);

use TypePHP\Internal\Config;

describe('Config Unit Tests', function () {
    afterEach(function () {
        Config::reset();
    });

    test('loads default configuration array', function () {
        $config = Config::get();

        expect($config)->toBeArray();
    });

    test('dynamically overrides configuration settings with set', function () {
        Config::set([
            'inline_vars' => [
                'scalars' => false,
            ],
        ]);

        $config = Config::get();

        expect($config['inline_vars']['scalars'])->toBeFalse();
    });

    test('resets configuration cache with reset', function () {
        Config::set(['cache' => false]);
        expect(Config::get()['cache'])->toBeFalse();

        Config::reset();
        expect(Config::get())->toBeArray();
    });
});