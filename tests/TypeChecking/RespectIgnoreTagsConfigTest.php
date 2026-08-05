<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\IgnoreTags\IgnoredMethod;
use TypePHP\TypePHP;

describe('Respect Ignore Tags Configuration (respect_ignore_tags)', function () {
    afterEach(function () {
        TypePHP::resetConfig();
    });

    test('forces type-checking on @typephp-ignore methods when respect_ignore_tags is set to false', function () {
        // Force TypePHP to ignore all @typephp-ignore docblock tags!
        TypePHP::setConfig(['respect_ignore_tags' => false]);

        $fixture = new IgnoredMethod();

        expect(fn () => $fixture->ignoredMethod(-100))
            ->toThrow(TypeError::class, 'positive-int');
    });
});