<?php

declare(strict_types=1);

use TypePHP\TypePHP;

/**
 * @param positive-int $id
 *
 * @return positive-int
 */
function testDisabledMasterSwitchFunction(int $id): int
{
    return $id;
}

describe('Global Master Switch (enabled => false)', function () {
    afterEach(function () {
        TypePHP::resetConfig();
    });

    test('disables all type checks when enabled is set to false', function () {
        TypePHP::setConfig(['enabled' => false]);

        $result = testDisabledMasterSwitchFunction(-50);
        expect($result)->toBe(-50);

        /** @var positive-int $age */
        $age = -100;
        expect($age)->toBe(-100);
    });
});