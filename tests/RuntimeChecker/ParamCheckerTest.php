<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Validator\TypeValidatorRegistry;

describe('ParamChecker Unit Tests', function () {
    test('checkParams accepts valid parameters matching function contract', function () {
        $registry = new TypeValidatorRegistry();
        $target = UserService::class . '::find';

        $err = ParamChecker::checkParams($target, ['id' => 10], new UserService(), $registry);

        expect($err)->toBeNull();
    });

    test('checkParams returns ErrorMessage on invalid parameter type', function () {
        $registry = new TypeValidatorRegistry();
        $target = UserService::class . '::find';

        $err = ParamChecker::checkParams($target, ['id' => -5], new UserService(), $registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('positive-int')
        ;
    });

    test('checkParams handles omitted optional parameters gracefully', function () {
        $registry = new TypeValidatorRegistry();
        $target = UserService::class . '::find';

        $err = ParamChecker::checkParams($target, [], new UserService(), $registry);

        expect($err)->toBeNull();
    });
});
