<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Tests\Fixtures\Services\FluentService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Validator\TypeValidatorRegistry;

describe('ReturnChecker Unit Tests', function () {
    test('checkReturn accepts valid return values matching function contract', function () {
        $registry = new TypeValidatorRegistry();
        $target = UserService::class . '::find';

        $value = ['id' => 10, 'name' => 'Alice'];
        $result = ReturnChecker::checkReturn($target, $value, new UserService(), ['id' => 10], $registry, fn () => null);

        expect($result)->toBe($value);
    });

    test('checkReturn returns ErrorMessage when return shape is violated', function () {
        $registry = new TypeValidatorRegistry();
        $target = UserService::class . '::find';

        $badValue = ['id' => -5, 'name' => 'Alice'];
        $result = ReturnChecker::checkReturn($target, $badValue, new UserService(), ['id' => -5], $registry, fn () => null);

        expect($result)->toBeInstanceOf(ErrorMessage::class);
    });

    test('checkReturn enforces $this identity constraints', function () {
        $registry = new TypeValidatorRegistry();
        $target = FluentService::class . '::setInvalidSelf';
        $service = new FluentService();

        $result = ReturnChecker::checkReturn($target, new FluentService(), $service, [], $registry, fn () => null);

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('must be $this instance')
        ;
    });
});
