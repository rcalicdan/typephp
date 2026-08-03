<?php

declare(strict_types=1);

use TypePHP\Internal\ErrorFactory;

test('error factory creates a TypeError with correct message', function () {
    $err = ErrorFactory::createError('Test argument error message');

    expect($err)->toBeInstanceOf(TypeError::class);
    expect($err->getMessage())->toBe('Test argument error message');
});
