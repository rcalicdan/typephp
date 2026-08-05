<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Liskov\AppChildService;
use TypePHP\Tests\Fixtures\Liskov\ChildLiskovService;
use TypePHP\Tests\Fixtures\Liskov\RenamedParamImplementation;
use TypePHP\Tests\Fixtures\Liskov\SimulatedVendorParent;

describe('Liskov Substitution Principle & Vendor Isolation', function () {
    describe('Edge Case 1: Partial Parameter Overriding (Gap Filling)', function () {
        test('inherits un-annotated parameter types from parent while respecting child overrides', function () {
            $service = new ChildLiskovService();

            // Valid call matching both parent's $id and child's $name
            expect($service->update(10, 'Alice'))->toBeTrue();

            // $id = -5 violates parent's inherited @param positive-int $id
            expect(fn () => $service->update(-5, 'Alice'))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            // $name = 'Charlie' violates child's @param 'Alice'|'Bob' $name
            expect(fn () => $service->update(10, 'Charlie'))
                ->toThrow(TypeError::class, "('Alice' | 'Bob')")
            ;
        });
    });

    describe('Edge Case 2: Parameter Renaming ($id -> $userId)', function () {
        test('enforces parameter type contract even when child renames parameter', function () {
            $service = new RenamedParamImplementation();

            expect($service->find(100))->toBeTrue();

            // $userId = -50 violates interface's @param positive-int $id
            expect(fn () => $service->find(-50))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });
    });

    describe('Edge Case 3: Vendor Isolation', function () {
        test('ignores inherited docblocks from excluded/vendor classes', function () {
            // Get path of simulated vendor file
            $ref = new ReflectionClass(SimulatedVendorParent::class);
            $filePath = str_replace('\\', '/', (string) $ref->getFileName());

            // Exclude this file (simulating vendor directory exclusion)
            Config::set(['exclude' => [$filePath]]);

            $appService = new AppChildService();

            // Since parent is excluded, negative-int docblock is IGNORED.
            // Passing positive int 100 succeeds cleanly!
            expect($appService->execute(100))->toBeTrue();

            Config::reset();
        });
    });
});
