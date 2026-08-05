<?php

declare(strict_types=1);

use TypePHP\Contract\HierarchyResolver;
use TypePHP\Tests\Fixtures\Services\BaseService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;

describe('HierarchyResolver Unit Tests', function () {
    test('resolves class inheritance chain from child to parent root', function () {
        $ref = new ReflectionClass(UserService::class);
        $hierarchy = HierarchyResolver::getClassHierarchy($ref);

        $classNames = array_map(fn ($r) => $r->getName(), $hierarchy);

        expect($classNames)->toContain(UserService::class)
            ->and($classNames)->toContain(BaseService::class)
        ;
    });

    test('resolves interface inheritance chain for implementing classes', function () {
        $ref = new ReflectionClass(CountableArrayAccess::class);
        $hierarchy = HierarchyResolver::getClassHierarchy($ref);

        $classNames = array_map(fn ($r) => $r->getName(), $hierarchy);

        expect($classNames)->toContain(CountableArrayAccess::class)
            ->and($classNames)->toContain(Countable::class)
            ->and($classNames)->toContain(ArrayAccess::class)
        ;
    });

    test('resolves method hierarchy across parent classes', function () {
        $ref = new ReflectionMethod(UserService::class, 'find');
        $hierarchy = HierarchyResolver::getMethodHierarchy($ref);

        expect($hierarchy)->toHaveCount(2)
            ->and($hierarchy[0]->getDeclaringClass()->getName())->toBe(UserService::class)
            ->and($hierarchy[1]->getDeclaringClass()->getName())->toBe(BaseService::class)
        ;
    });
});
