<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Validator\TypeValidatorRegistry;

beforeEach(function () {
    $this->registry = new TypeValidatorRegistry();

    $config = new ParserConfig(usedAttributes: []);
    $this->lexer = new Lexer($config);
    $constExprParser = new ConstExprParser($config);
    $this->typeParser = new TypeParser($config, $constExprParser);
});

function parseType(string $typeString, Lexer $lexer, TypeParser $typeParser): TypeNode
{
    $tokens = new TokenIterator($lexer->tokenize($typeString));

    return $typeParser->parse($tokens);
}

describe('IdentifierValidator', function () {
    test('validates basic primitives', function () {
        $intNode = parseType('int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(10, $intNode, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $intNode, 'arg'))->toBeInstanceOf(TypeError::class);

        $stringNode = parseType('string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $stringNode, 'arg'))->toBeNull();
        expect($this->registry->validate(123, $stringNode, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('validates special string types', function () {
        $nonEmpty = parseType('non-empty-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $nonEmpty, 'arg'))->toBeNull();
        expect($this->registry->validate('', $nonEmpty, 'arg'))->toBeInstanceOf(TypeError::class);

        $numericStr = parseType('numeric-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('123.45', $numericStr, 'arg'))->toBeNull();
        expect($this->registry->validate('not_a_number', $numericStr, 'arg'))->toBeInstanceOf(TypeError::class);

        $lowerStr = parseType('lowercase-string', $this->lexer, $this->typeParser);
        expect($this->registry->validate('hello', $lowerStr, 'arg'))->toBeNull();
        expect($this->registry->validate('Hello', $lowerStr, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('validates int ranges and constraints', function () {
        $posInt = parseType('positive-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(5, $posInt, 'arg'))->toBeNull();
        expect($this->registry->validate(-5, $posInt, 'arg'))->toBeInstanceOf(TypeError::class);

        $negInt = parseType('negative-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-5, $negInt, 'arg'))->toBeNull();
        expect($this->registry->validate(5, $negInt, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('validates truthy and falsy', function () {
        $truthy = parseType('truthy', $this->lexer, $this->typeParser);
        expect($this->registry->validate('true', $truthy, 'arg'))->toBeNull();
        expect($this->registry->validate(0, $truthy, 'arg'))->toBeInstanceOf(TypeError::class);

        $falsy = parseType('falsy', $this->lexer, $this->typeParser);
        expect($this->registry->validate(false, $falsy, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $falsy, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: validates class-string against interfaces, traits, and enums', function () {
        $classString = parseType('class-string', $this->lexer, $this->typeParser);

        expect($this->registry->validate(DateTimeInterface::class, $classString, 'arg'))->toBeNull();
        expect($this->registry->validate('NonExistentClass12345', $classString, 'arg'))->toBeInstanceOf(TypeError::class);
        expect($this->registry->validate(123, $classString, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: validates callable-string', function () {
        $callableStr = parseType('callable-string', $this->lexer, $this->typeParser);

        expect($this->registry->validate('strlen', $callableStr, 'arg'))->toBeNull();
        expect($this->registry->validate('non_existent_function_abc_123', $callableStr, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: validates boundary conditions for positive, negative, and non-zero ints', function () {
        $posInt = parseType('positive-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $posInt, 'arg'))->toBeInstanceOf(TypeError::class); // 0 is not positive

        $nonZero = parseType('non-zero-int', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $nonZero, 'arg'))->toBeInstanceOf(TypeError::class);
        expect($this->registry->validate(-1, $nonZero, 'arg'))->toBeNull();
        expect($this->registry->validate(1, $nonZero, 'arg'))->toBeNull();
    });
});

describe('ConstValidator', function () {
    test('validates string and integer literals', function () {
        $strLiteral = parseType("'active'", $this->lexer, $this->typeParser);
        expect($this->registry->validate('active', $strLiteral, 'arg'))->toBeNull();
        expect($this->registry->validate('inactive', $strLiteral, 'arg'))->toBeInstanceOf(TypeError::class);

        $intLiteral = parseType('42', $this->lexer, $this->typeParser);
        expect($this->registry->validate(42, $intLiteral, 'arg'))->toBeNull();
        expect($this->registry->validate(100, $intLiteral, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: strict type matching for constant literals', function () {
        $intLiteral = parseType('42', $this->lexer, $this->typeParser);
        expect($this->registry->validate('42', $intLiteral, 'arg'))->toBeInstanceOf(TypeError::class); // String '42' != Int 42

        $trueLiteral = parseType('true', $this->lexer, $this->typeParser);
        expect($this->registry->validate(1, $trueLiteral, 'arg'))->toBeInstanceOf(TypeError::class); // Int 1 != bool true
        expect($this->registry->validate(true, $trueLiteral, 'arg'))->toBeNull();

        $nullLiteral = parseType('null', $this->lexer, $this->typeParser);
        expect($this->registry->validate(null, $nullLiteral, 'arg'))->toBeNull();
        expect($this->registry->validate(false, $nullLiteral, 'arg'))->toBeInstanceOf(TypeError::class);
    });
});

describe('ArrayShapeValidator', function () {
    test('checks required and optional keys', function () {
        $shape = parseType('array{id: int, name: string, email?: string}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com'], $shape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1, 'name' => 'Alice'], $shape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1], $shape, 'arg'))->toBeInstanceOf(TypeError::class);
        expect($this->registry->validate(['id' => 'not_an_int', 'name' => 'Alice'], $shape, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: sealed shapes reject unexpected extra keys', function () {
        $sealedShape = parseType('array{id: int}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['id' => 1], $sealedShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1, 'extra' => 'value'], $sealedShape, 'arg'))->toBeInstanceOf(TypeError::class);
    });
    
    test('edge case: unsealed shapes allow extra keys matching unsealed type', function () {
        $unsealedShape = parseType('array{id: int, ...<string, string>}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['id' => 1, 'role' => 'admin'], $unsealedShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 1, 'role' => 999], $unsealedShape, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: nested array shapes', function () {
        $nestedShape = parseType('array{user: array{id: int, name: string}}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['user' => ['id' => 1, 'name' => 'Alice']], $nestedShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['user' => ['id' => 'invalid']], $nestedShape, 'arg'))->toBeInstanceOf(TypeError::class);
    });
});

describe('GenericValidator', function () {
    test('checks int range bounds int<1, 10>', function () {
        $rangeNode = parseType('int<1, 10>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(5, $rangeNode, 'arg'))->toBeNull();
        expect($this->registry->validate(0, $rangeNode, 'arg'))->toBeInstanceOf(TypeError::class);
        expect($this->registry->validate(15, $rangeNode, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('checks list<T> and array<K, V>', function () {
        $listNode = parseType('list<string>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['a', 'b', 'c'], $listNode, 'arg'))->toBeNull();
        expect($this->registry->validate(['a' => 'b'], $listNode, 'arg'))->toBeInstanceOf(TypeError::class);

        $assocArrayNode = parseType('array<string, int>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['age' => 30, 'score' => 100], $assocArrayNode, 'arg'))->toBeNull();
        expect($this->registry->validate(['age' => 'thirty'], $assocArrayNode, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: int range bounds with min, max, and wildcard *', function () {
        $minRange = parseType('int<min, 100>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(-99999, $minRange, 'arg'))->toBeNull();
        expect($this->registry->validate(100, $minRange, 'arg'))->toBeNull();
        expect($this->registry->validate(101, $minRange, 'arg'))->toBeInstanceOf(TypeError::class);

        $maxRange = parseType('int<0, max>', $this->lexer, $this->typeParser);
        expect($this->registry->validate(0, $maxRange, 'arg'))->toBeNull();
        expect($this->registry->validate(999999, $maxRange, 'arg'))->toBeNull();
        expect($this->registry->validate(-1, $maxRange, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: non-empty-list rejects empty array', function () {
        $nonEmptyList = parseType('non-empty-list<int>', $this->lexer, $this->typeParser);

        expect($this->registry->validate([10, 20], $nonEmptyList, 'arg'))->toBeNull();
        expect($this->registry->validate([], $nonEmptyList, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: nested generic arrays array<string, list<int>>', function () {
        $nestedGeneric = parseType('array<string, list<int>>', $this->lexer, $this->typeParser);

        expect($this->registry->validate(['scores' => [10, 20, 30]], $nestedGeneric, 'arg'))->toBeNull();
        expect($this->registry->validate(['scores' => ['a' => 10]], $nestedGeneric, 'arg'))->toBeInstanceOf(TypeError::class);
    });
});

describe('NullableValidator', function () {
    test('handles null and wrapped types', function () {
        $nullableInt = parseType('?int', $this->lexer, $this->typeParser);

        expect($this->registry->validate(null, $nullableInt, 'arg'))->toBeNull();
        expect($this->registry->validate(100, $nullableInt, 'arg'))->toBeNull();
        expect($this->registry->validate('string', $nullableInt, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: nullable array shape ?array{id: int}', function () {
        $nullableShape = parseType('?array{id: int}', $this->lexer, $this->typeParser);

        expect($this->registry->validate(null, $nullableShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 10], $nullableShape, 'arg'))->toBeNull();
        expect($this->registry->validate(['id' => 'invalid'], $nullableShape, 'arg'))->toBeInstanceOf(TypeError::class);
    });
});

describe('UnionValidator', function () {
    test('accepts valid choices and rejects invalid choices', function () {
        $union = parseType('int|string', $this->lexer, $this->typeParser);

        expect($this->registry->validate(10, $union, 'arg'))->toBeNull();
        expect($this->registry->validate('hello', $union, 'arg'))->toBeNull();
        expect($this->registry->validate(true, $union, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: literal string union active|pending|closed', function () {
        $enumUnion = parseType("'active'|'pending'|'closed'", $this->lexer, $this->typeParser);

        expect($this->registry->validate('active', $enumUnion, 'arg'))->toBeNull();
        expect($this->registry->validate('pending', $enumUnion, 'arg'))->toBeNull();
        expect($this->registry->validate('archived', $enumUnion, 'arg'))->toBeInstanceOf(TypeError::class);
    });
});

describe('IntersectionValidator', function () {
    test('requires value to satisfy all types', function () {
        $intersection = parseType('Countable&ArrayAccess', $this->lexer, $this->typeParser);

        $validObj = new ArrayObject();
        $invalidObj = new stdClass();

        expect($this->registry->validate($validObj, $intersection, 'arg'))->toBeNull();
        expect($this->registry->validate($invalidObj, $intersection, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: object failing one interface in intersection', function () {
        $intersection = parseType('Countable&ArrayAccess', $this->lexer, $this->typeParser);

        $countableOnly = new class implements Countable {
            public function count(): int
            {
                return 0;
            }
        };

        expect($this->registry->validate($countableOnly, $intersection, 'arg'))->toBeInstanceOf(TypeError::class);
    });
});

describe('ArrayValidator', function () {
    test('checks array element types', function () {
        $intArray = parseType('int[]', $this->lexer, $this->typeParser);

        expect($this->registry->validate([1, 2, 3], $intArray, 'arg'))->toBeNull();
        expect($this->registry->validate([1, 'invalid_string', 3], $intArray, 'arg'))->toBeInstanceOf(TypeError::class);
    });

    test('edge case: empty array is valid for typed array', function () {
        $intArray = parseType('int[]', $this->lexer, $this->typeParser);

        expect($this->registry->validate([], $intArray, 'arg'))->toBeNull();
    });
});
