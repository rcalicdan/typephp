<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError as TypePHPTypeError;

/**
 * @param positive-int $id
 */
function testCustomExceptionFunction(int $id): bool
{
    return true;
}

describe('Custom Exception Catching (TypePHP\Exception\TypeError)', function () {
    test('allows catching TypePHP contract errors using specific TypePHP\Exception\TypeError', function () {
        $caughtSpecific = false;

        try {
            testCustomExceptionFunction(-50);
        } catch (TypePHPTypeError $e) {
            $caughtSpecific = true;
            expect($e->getMessage())->toContain('positive-int');
        }

        expect($caughtSpecific)->toBeTrue();
    });

    test('allows catching TypePHP contract errors using native PHP TypeError polymorphically', function () {
        $caughtNative = false;

        try {
            testCustomExceptionFunction(-100);
        } catch (TypeError $e) {
            $caughtNative = true;
            expect($e->getMessage())->toContain('positive-int');
        }

        expect($caughtNative)->toBeTrue();
    });
});
