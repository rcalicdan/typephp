<?php

declare(strict_types=1);

use PhpParser\Node;
use TypePHP\Internal\Visitor\ScopeManager;

describe('ScopeManager Unit Tests', function () {
    test('manages scoped variable stack frames', function () {
        $manager = new ScopeManager();

        $manager->pushScope();
        $manager->extractVarDocblock('/** @var positive-int $age */');

        expect($manager->getVarTypeFromScope('age'))->toBe('positive-int');

        $manager->popScope();
        expect($manager->getVarTypeFromScope('age'))->toBeNull();
    });

    test('resolves variables from outer scope if not overridden in inner scope', function () {
        $manager = new ScopeManager();

        $manager->pushScope();
        $manager->extractVarDocblock('/** @var positive-int $globalId */');

        $manager->pushScope(); // Inner scope
        expect($manager->getVarTypeFromScope('globalId'))->toBe('positive-int');

        $manager->popScope();
        $manager->popScope();
    });

    test('infers variable name from assignment expression if unnamed in docblock', function () {
        $manager = new ScopeManager();
        $manager->pushScope();

        $assign = new Node\Expr\Assign(
            new Node\Expr\Variable('username'),
            new Node\Scalar\String_('Alice')
        );

        $manager->extractVarDocblock('/** @var non-empty-string */', $assign);

        expect($manager->getVarTypeFromScope('username'))->toBe('non-empty-string');
    });
});