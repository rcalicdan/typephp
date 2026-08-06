<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Domain\User;
use TypePHP\Tests\Fixtures\Types\PropertyHooks;

beforeEach(function () {
    Config::reset();

    Config::set([
        'inline_vars' => [
            'properties' => true,
            'generics' => true,
            'callables' => true,
            'scalars' => true,
            'shapes' => true,
            'objects' => true,
        ],
    ]);
});

afterEach(function () {
    Config::reset();
});

describe('PHP 8.4 Property Hooks Validation', function () {
    test('validates return values of short get property hooks (get => $expr)', function () {
        $fixture = new PropertyHooks();

        expect(fn () => $fixture->shortGetNumbers)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$shortGetNumbers[0] must be of type int, string 'hello' given")
        ;
    });

    test('validates return values of block get property hooks (get { return $expr; })', function () {
        $fixture = new PropertyHooks();

        expect(fn () => $fixture->blockGetNumbers)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$blockGetNumbers[2] must be of type int, string 'invalid' given")
        ;
    });

    test('validates incoming values of short set property hooks (set => $expr)', function () {
        $fixture = new PropertyHooks();

        $fixture->shortSetNumber = 42;
        expect($fixture->_shortSetNumber)->toBe(42);

        expect(fn () => $fixture->shortSetNumber = -5)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$shortSetNumber must be of type positive-int, negative int (-5) given")
        ;
    });

    test('validates incoming values of block set property hooks (set($val) { ... })', function () {
        $fixture = new PropertyHooks();

        $fixture->blockSetNumber = 100;
        expect($fixture->_blockSetNumber)->toBe(100);

        expect(fn () => $fixture->blockSetNumber = -10)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$blockSetNumber must be of type positive-int, negative int (-10) given")
        ;
    });

    test('validates asymmetric visibility properties combined with property hooks', function () {
        $profile = new User();

        $profile->updateProfile(100, 'Bob');
        expect($profile->id)->toBe(100);
        expect($profile->username)->toBe('Bob');

        expect(fn () => $profile->updateProfile(-5, 'Bob'))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $profile->updateProfile(100, ''))
            ->toThrow(TypeError::class, 'non-empty-string');
    });
});
