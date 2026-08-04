<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;
use TypePHP\Tests\Fixtures\Types\NonCpmStrings;

describe('ContractParser Unit Tests', function () {
    test('parses function/method contracts and caches results', function () {
        $target = UserService::class . '::find';

        $contract1 = ContractParser::parse($target);
        $contract2 = ContractParser::parse($target);

        expect($contract1)->toBeArray()
            ->and($contract1['types'])->toHaveKey('id')
            ->and($contract1)->toBe($contract2); // Exact cached reference
    });

    test('parses property @var docblocks using parseProperty', function () {
        $typeNode = ContractParser::parseProperty(ConfiguredProperty::class, 'numbers');

        expect($typeNode)->toBeInstanceOf(ArrayTypeNode::class)
            ->and($typeNode->type)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($typeNode->type->name)->toBe('int');
    });

    test('falls back to property @var docblock for constructor property promotion', function () {
        $target = NonCpmStrings::class . '::__construct';
        $contract = ContractParser::parse($target);

        expect($contract['types'])->toHaveKey('strings')
            ->and($contract['types']['strings'])->toBeInstanceOf(ArrayTypeNode::class);
    });
});