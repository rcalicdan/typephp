<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\GeneratorChecker;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @return Generator<string, positive-int, positive-int, void>
 */
function sampleGeneratorFixture(): Generator
{
    yield 'a' => 10;
}

describe('GeneratorChecker Unit Tests', function () {
    test('checkYield accepts valid yielded key and value', function () {
        $registry = new TypeValidatorRegistry();

        $result = GeneratorChecker::checkYield('sampleGeneratorFixture', 'a', 10, $registry);

        expect($result)->toBe(10);
    });

    test('checkYield throws TypeError on invalid yielded value', function () {
        $registry = new TypeValidatorRegistry();

        expect(fn () => GeneratorChecker::checkYield('sampleGeneratorFixture', 'a', -50, $registry))
            ->toThrow(TypeError::class, 'Return iterator value')
        ;
    });

    test('checkSend accepts valid TSend input value', function () {
        $registry = new TypeValidatorRegistry();

        $result = GeneratorChecker::checkSend('sampleGeneratorFixture', 100, $registry);

        expect($result)->toBe(100);
    });

    test('checkSend throws TypeError on invalid TSend input value', function () {
        $registry = new TypeValidatorRegistry();

        expect(fn () => GeneratorChecker::checkSend('sampleGeneratorFixture', -500, $registry))
            ->toThrow(TypeError::class, 'Generator sent value (TSend)')
        ;
    });
});
