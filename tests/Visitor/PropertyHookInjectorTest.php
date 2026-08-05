<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit\Visitor;

use PhpParser\Node;
use TypePHP\Internal\Visitor\PropertyHookInjector;

describe('PropertyHookInjector Unit Tests', function () {
    test('wraps short get property hooks (get => $expr)', function () {
        $hook = new Node\PropertyHook(
            name: 'get',
            body: new Node\Scalar\String_('invalid')
        );

        $prop = new Node\Stmt\Property(
            flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
            props: [new Node\PropertyItem('title')],
            hooks: [$hook]
        );

        PropertyHookInjector::process($prop);

        expect($prop->hooks[0]->body)->toBeInstanceOf(Node\Expr\Ternary::class);
    });

    test('wraps set property hooks and injects paramCheckStmt with typephp_injected attribute', function () {
        $hook = new Node\PropertyHook(
            name: 'set',
            body: [
                new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'title'),
                        new Node\Expr\Variable('value')
                    )
                ),
            ]
        );

        $prop = new Node\Stmt\Property(
            flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
            props: [new Node\PropertyItem('title')],
            hooks: [$hook]
        );

        PropertyHookInjector::process($prop);

        $body = $prop->hooks[0]->body;
        expect($body)->toBeArray()
            ->and($body[0])->toBeInstanceOf(Node\Stmt\Expression::class)
            ->and($body[0]->getAttribute('typephp_injected'))->toBeTrue()
        ;
    });
});
