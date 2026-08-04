<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Types\ArrayAccessOnly;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

/**
 * Contract function accepting Scalar and Literal Unions
 *
 * @param positive-int|non-empty-string|'active'|'pending' $val
 */
function testScalarUnionContract(mixed $val): bool
{
    return true;
}

/**
 * Contract function accepting Intersection Types
 *
 * @param \Countable&\ArrayAccess $collection
 */
function testIntersectionContract(object $collection): bool
{
    return true;
}

/**
 * Contract function accepting Unions of Shapes and Lists
 *
 * @param array{id: positive-int, status: 'active'|'inactive'}[]|list<non-empty-string> $data
 */
function testUnionOfShapesAndListsContract(array $data): int
{
    return count($data);
}

/**
 * Contract function accepting Generics with Unions
 *
 * @param Producer<Dog|Cat> $producer
 */
function testGenericWithUnionContract(Producer $producer): mixed
{
    return $producer->item;
}

/**
 * Contract function accepting Generics with Intersections
 *
 * @param Producer<\Countable&\ArrayAccess> $producer
 */
function testGenericWithIntersectionContract(Producer $producer): mixed
{
    return $producer->item;
}

/**
 * Contract function accepting Complex Unions of Intersections
 *
 * @param (\Countable&\ArrayAccess)|(\Iterator&\Countable) $payload
 */
function testUnionOfIntersectionsContract(object $payload): bool
{
    return true;
}

describe('Scalar and Literal Union Types', function () {
    test('accepts positive-int, non-empty-string, or enum literals in union', function () {
        expect(testScalarUnionContract(100))->toBeTrue();
        expect(testScalarUnionContract('user_100'))->toBeTrue();
        expect(testScalarUnionContract('active'))->toBeTrue();
        expect(testScalarUnionContract('pending'))->toBeTrue();
    });

    test('throws TypeError when value violates all options in union', function () {
        expect(fn () => testScalarUnionContract(-50))
            ->toThrow(TypeError::class);

        expect(fn () => testScalarUnionContract(''))
            ->toThrow(TypeError::class);

        expect(fn () => testScalarUnionContract(false))
            ->toThrow(TypeError::class);
    });
});

describe('Intersection Types (Countable & ArrayAccess)', function () {
    test('accepts object implementing both Countable and ArrayAccess', function () {
        $fixture = new CountableArrayAccess();
        expect(testIntersectionContract($fixture))->toBeTrue();
    });

    test('throws TypeError when object only implements one interface in intersection', function () {
        expect(fn () => testIntersectionContract(new CountableOnly()))
            ->toThrow(TypeError::class);

        expect(fn () => testIntersectionContract(new ArrayAccessOnly()))
            ->toThrow(TypeError::class);
    });
});

describe('Unions of Array Shapes and Lists', function () {
    test('accepts array of user shapes matching union variant A', function () {
        $shapes = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'inactive'],
        ];

        expect(testUnionOfShapesAndListsContract($shapes))->toBe(2);
    });

    test('accepts list of non-empty strings matching union variant B', function () {
        $tags = ['php', 'testing', 'pest'];

        expect(testUnionOfShapesAndListsContract($tags))->toBe(3);
    });

    test('throws TypeError when array fails all union shape/list variants', function () {
        $invalidShapes = [
            ['id' => -1, 'status' => 'active'],
        ];

        expect(fn () => testUnionOfShapesAndListsContract($invalidShapes))
            ->toThrow(TypeError::class);

        $invalidTags = ['php', ''];

        expect(fn () => testUnionOfShapesAndListsContract($invalidTags))
            ->toThrow(TypeError::class);
    });
});

describe('Generics with Unions (Producer<Dog|Cat>)', function () {
    test('accepts Producer holding Dog or Cat', function () {
        $dogProducer = new Producer(new Dog());
        $catProducer = new Producer(new Cat());

        expect(testGenericWithUnionContract($dogProducer))->toBeInstanceOf(Dog::class);
        expect(testGenericWithUnionContract($catProducer))->toBeInstanceOf(Cat::class);
    });

    test('throws TypeError when Producer holds type outside the union', function () {
        $carProducer = new Producer(new Car());

        expect(fn () => testGenericWithUnionContract($carProducer))
            ->toThrow(TypeError::class);
    });
});

describe('Generics with Intersections (Producer<Countable & ArrayAccess>)', function () {
    test('accepts Producer holding an object satisfying the intersection', function () {
        $producer = new Producer(new CountableArrayAccess());

        expect(testGenericWithIntersectionContract($producer))->toBeInstanceOf(CountableArrayAccess::class);
    });

    test('throws TypeError when Producer holds an object failing the intersection', function () {
        $producer = new Producer(new CountableOnly());

        expect(fn () => testGenericWithIntersectionContract($producer))
            ->toThrow(TypeError::class);
    });
});

describe('Complex Nested Unions of Intersections', function () {
    test('accepts object matching first intersection variant (Countable & ArrayAccess)', function () {
        $fixture = new CountableArrayAccess();

        expect(testUnionOfIntersectionsContract($fixture))->toBeTrue();
    });

    test('accepts object matching second intersection variant (Iterator & Countable)', function () {
        $arrayIterator = new ArrayIterator([1, 2, 3]);

        expect(testUnionOfIntersectionsContract($arrayIterator))->toBeTrue();
    });

    test('throws TypeError when object fails both intersection variants in union', function () {
        $fixture = new CountableOnly();

        expect(fn () => testUnionOfIntersectionsContract($fixture))
            ->toThrow(TypeError::class);
    });
});