<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Resolver\TemplateSubstitutor;

describe('TemplateSubstitutor Unit Tests', function () {
    test('substitutes simple identifier template placeholders', function () {
        $templateNode = new IdentifierTypeNode('T');
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($templateNode, $boundTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('int')
        ;
    });

    test('falls back to default template type when unbound (@template T = string)', function () {
        $templateNode = new IdentifierTypeNode('T');
        $declaredTemplates = [
            'T' => new TemplateTagValueNode(
                name: 'T',
                bound: null,
                description: '',
                default: new IdentifierTypeNode('string')
            ),
        ];

        $result = TemplateSubstitutor::substitute($templateNode, [], $declaredTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('string')
        ;
    });

    test('prefers default template over bound template when unbound (@template T of object = stdClass)', function () {
        $templateNode = new IdentifierTypeNode('T');
        $declaredTemplates = [
            'T' => new TemplateTagValueNode(
                name: 'T',
                bound: new IdentifierTypeNode('object'),
                description: '',
                default: new IdentifierTypeNode('stdClass')
            ),
        ];

        $result = TemplateSubstitutor::substitute($templateNode, [], $declaredTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('stdClass')
        ;
    });

    test('falls back to bound template when default is null (@template T of object)', function () {
        $templateNode = new IdentifierTypeNode('T');
        $declaredTemplates = [
            'T' => new TemplateTagValueNode(
                name: 'T',
                bound: new IdentifierTypeNode('object'),
                description: '',
                default: null
            ),
        ];

        $result = TemplateSubstitutor::substitute($templateNode, [], $declaredTemplates);

        expect($result)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($result->name)->toBe('object')
        ;
    });

    test('substitutes array template placeholders (T[] -> int[])', function () {
        $arrayNode = new ArrayTypeNode(new IdentifierTypeNode('T'));
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($arrayNode, $boundTemplates);

        expect($result)->toBeInstanceOf(ArrayTypeNode::class)
            ->and($result->type->name)->toBe('int')
        ;
    });

    test('substitutes generic template parameters (Container<T> -> Container<string>)', function () {
        $genericNode = new GenericTypeNode(
            new IdentifierTypeNode('Container'),
            [new IdentifierTypeNode('T')]
        );
        $boundTemplates = ['T' => new IdentifierTypeNode('string')];

        $result = TemplateSubstitutor::substitute($genericNode, $boundTemplates);

        expect($result)->toBeInstanceOf(GenericTypeNode::class)
            ->and($result->genericTypes[0]->name)->toBe('string')
        ;
    });

    test('substitutes template placeholders inside nullable types (?T -> ?int)', function () {
        $nullableNode = new NullableTypeNode(new IdentifierTypeNode('T'));
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($nullableNode, $boundTemplates);

        expect($result)->toBeInstanceOf(NullableTypeNode::class)
            ->and($result->type->name)->toBe('int')
        ;
    });

    test('substitutes template placeholders inside union types (T|string -> int|string)', function () {
        $unionNode = new UnionTypeNode([
            new IdentifierTypeNode('T'),
            new IdentifierTypeNode('string'),
        ]);
        $boundTemplates = ['T' => new IdentifierTypeNode('int')];

        $result = TemplateSubstitutor::substitute($unionNode, $boundTemplates);

        expect($result)->toBeInstanceOf(UnionTypeNode::class)
            ->and($result->types[0]->name)->toBe('int')
            ->and($result->types[1]->name)->toBe('string')
        ;
    });

    test('leaves non-template types untouched', function () {
        $intNode = new IdentifierTypeNode('int');
        $boundTemplates = ['T' => new IdentifierTypeNode('string')];

        $result = TemplateSubstitutor::substitute($intNode, $boundTemplates);

        expect($result->name)->toBe('int');
    });
});
