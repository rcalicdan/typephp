<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit;

use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\TypePHP;

/**
 * @template ItemType
 */
class CustomTemplateNameBox
{
    /** @var ItemType */
    public mixed $item = null;
}

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

    test('inspects single template parameter automatically even if custom template name is used', function () {
        /** @var CustomTemplateNameBox<Dog> $box */
        $box = new CustomTemplateNameBox();

        // Automatically inspects 'ItemType' without needing to guess the template name!
        expect(TypePHP::getGenericType($box))->toBe(Dog::class)
            ->and(TypePHP::getGenericType($box, 'ItemType'))->toBe(Dog::class)
            ->and(TypePHP::getGenericTypes($box))->toBe(['ItemType' => Dog::class]);
    });
});
