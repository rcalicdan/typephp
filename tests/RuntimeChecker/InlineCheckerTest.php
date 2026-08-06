<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\InlineChecker;
use TypePHP\Internal\Config;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;
use TypePHP\Validator\TypeValidatorRegistry;

describe('InlineChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        Config::set([
            'inline_vars' => [
                'properties' => true,
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
        ]);
    });

    afterEach(function () {
        Config::reset();
    });

    test('checkVariable validates scalar types when enabled in config', function () {
        $registry = new TypeValidatorRegistry();

        $valid = InlineChecker::checkVariable(10, 'positive-int', 'age', __FILE__, $registry);
        expect($valid)->toBe(10);

        $invalid = InlineChecker::checkVariable(-5, 'positive-int', 'age', __FILE__, $registry);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class);
    });

    test('checkVariable ignores scalar validation when disabled in config', function () {
        Config::set(['inline_vars' => ['scalars' => false]]);
        $registry = new TypeValidatorRegistry();

        $result = InlineChecker::checkVariable(-5, 'positive-int', 'age', __FILE__, $registry);
        expect($result)->toBe(-5);
    });

    test('checkProperty validates class properties against @var docblock', function () {
        $registry = new TypeValidatorRegistry();
        $fixture = new ConfiguredProperty();

        $valid = InlineChecker::checkProperty([1, 2, 3], $fixture, 'numbers', __FILE__, $registry);
        expect($valid)->toBe([1, 2, 3]);

        $invalid = InlineChecker::checkProperty(['invalid'], $fixture, 'numbers', __FILE__, $registry);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class);
    });

    test('checkProperty ignores property validation when disabled in config', function () {
        Config::set(['inline_vars' => ['properties' => false]]);
        $registry = new TypeValidatorRegistry();
        $fixture = new ConfiguredProperty();

        $result = InlineChecker::checkProperty(['invalid'], $fixture, 'numbers', __FILE__, $registry);
        expect($result)->toBe(['invalid']);
    });
});
