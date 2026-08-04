<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Container;

describe('TemplateManager Unit Tests', function () {
    afterEach(function () {
        TemplateManager::popCallFrame('testFunc');
    });

    test('infers AST TypeNode correctly from raw PHP values', function () {
        expect(TemplateManager::inferTypeFromValue(10)->__toString())->toBe('int')
            ->and(TemplateManager::inferTypeFromValue('hello')->__toString())->toBe('string')
            ->and(TemplateManager::inferTypeFromValue(12.34)->__toString())->toBe('float')
            ->and(TemplateManager::inferTypeFromValue(true)->__toString())->toBe('bool')
            ->and(TemplateManager::inferTypeFromValue([1, 2, 3])->__toString())->toBe('list')
            ->and(TemplateManager::inferTypeFromValue(['a' => 1])->__toString())->toBe('array')
            ->and(TemplateManager::inferTypeFromValue(null)->__toString())->toBe('null')
            ->and(TemplateManager::inferTypeFromValue(new Dog())->__toString())->toBe(Dog::class);
    });

    test('manages function call stack frame template bindings', function () {
        TemplateManager::pushCallFrame('testFunc');

        expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeFalse();

        TemplateManager::bindTemplate('testFunc', null, 'T', new IdentifierTypeNode('int'));

        expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeTrue();
        expect(TemplateManager::getBoundType('testFunc', null, 'T')->__toString())->toBe('int');

        TemplateManager::popCallFrame('testFunc');
        expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeFalse();
    });

    test('binds object instances via WeakMap', function () {
        $container = new Container(new Dog());
        $typeNode = new GenericTypeNode(
            new IdentifierTypeNode(Container::class),
            [new IdentifierTypeNode(Dog::class)]
        );

        $err = TemplateManager::bindInstanceFromNode($container, $typeNode);

        expect($err)->toBeNull();
        expect(TemplateManager::isBound('none', $container, 'T'))->toBeTrue();
        expect(TemplateManager::getBoundType('none', $container, 'T')->__toString())->toBe(Dog::class);
    });

    test('validates covariance rules in checkVariance', function () {
        $existingDog = new IdentifierTypeNode(Dog::class);
        $expectedAnimal = new IdentifierTypeNode(Animal::class);

        // Dog is an Animal -> Covariant check passes
        $isCovariantValid = TemplateManager::checkVariance(
            $existingDog,
            $expectedAnimal,
            GenericTypeNode::VARIANCE_COVARIANT
        );
        expect($isCovariantValid)->toBeTrue();

        // Car is not an Animal -> Covariant check fails
        $existingCar = new IdentifierTypeNode(Car::class);
        $isCarValid = TemplateManager::checkVariance(
            $existingCar,
            $expectedAnimal,
            GenericTypeNode::VARIANCE_COVARIANT
        );
        expect($isCarValid)->toBeFalse();
    });

    test('validates contravariance rules in checkVariance', function () {
        $existingAnimal = new IdentifierTypeNode(Animal::class);
        $expectedDog = new IdentifierTypeNode(Dog::class);

        // Animal is a supertype of Dog -> Contravariant check passes
        $isContravariantValid = TemplateManager::checkVariance(
            $existingAnimal,
            $expectedDog,
            GenericTypeNode::VARIANCE_CONTRAVARIANT
        );
        expect($isContravariantValid)->toBeTrue();

        // Cat is a subtype, not a supertype -> Contravariant check fails
        $existingCat = new IdentifierTypeNode(Cat::class);
        $isCatValid = TemplateManager::checkVariance(
            $existingCat,
            $expectedDog,
            GenericTypeNode::VARIANCE_CONTRAVARIANT
        );
        expect($isCatValid)->toBeFalse();
    });
});