<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit;

use TypePHP\TypePHP;

describe('TypePHP Public Facade Unit Tests', function () {
    afterEach(function () {
        TypePHP::resetConfig();
    });

    test('gets resolved configuration using getConfig', function () {
        $config = TypePHP::getConfig();

        expect($config)->toBeArray()
            ->and($config)->toHaveKey('cache')
            ->and($config)->toHaveKey('inline_vars')
        ;
    });

    test('dynamically overrides configuration settings using setConfig', function () {
        TypePHP::setConfig([
            'inline_vars' => [
                'scalars' => false,
            ],
        ]);

        $config = TypePHP::getConfig();

        expect($config['inline_vars']['scalars'])->toBeFalse();
    });

    test('resets configuration using resetConfig', function () {
        TypePHP::setConfig(['cache' => false]);
        expect(TypePHP::getConfig()['cache'])->toBeFalse();

        TypePHP::resetConfig();
        expect(TypePHP::getConfig()['cache'])->toBeTrue();
    });
});
