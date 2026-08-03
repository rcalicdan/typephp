<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class ContractVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node)
    {
        // Function / Method contract injection
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            return $this->injectFunctionContract($node);
        }

        // @var Docblock Pre-binding on assignments ($var = new Class())
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
            return $this->injectVarPrebinding($node);
        }

        return null;
    }

    private function injectFunctionContract(Node\Stmt\Function_|Node\Stmt\ClassMethod $node)
    {
        if ($node->stmts === null) {
            return null;
        }

        $doc = $node->getDocComment();
        if (! $doc) {
            return null;
        }

        $docText = $doc->getText();
        $hasParam = str_contains($docText, '@param');
        $hasReturn = str_contains($docText, '@return') || str_contains($docText, '@phpstan-return') || str_contains($docText, '@psalm-return');

        if (! $hasParam && ! $hasReturn) {
            return null;
        }

        $hasThis = ($node instanceof Node\Stmt\ClassMethod) && ! $node->isStatic();
        $thisArg = $hasThis
            ? new Node\Expr\Variable('this')
            : new Node\Expr\ConstFetch(new Node\Name('null'));

        $injectedStmts = [];

        if ($hasParam) {
            $injectedStmts[] = new Node\Stmt\If_(
                new Node\Expr\Assign(
                    new Node\Expr\Variable('__typephpErr'),
                    new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkParams'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                            new Node\Arg($thisArg),
                        ]
                    )
                ),
                [
                    'stmts' => [
                        new Node\Stmt\Expression(
                            new Node\Expr\Throw_(new Node\Expr\Variable('__typephpErr'))
                        ),
                    ],
                ]
            );

            // Callable wrapping
            if (str_contains($docText, 'callable') || str_contains($docText, 'Closure')) {
                foreach ($node->params as $param) {
                    if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $paramName = $param->var->name;
                        $injectedStmts[] = new Node\Stmt\Expression(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable($paramName),
                                new Node\Expr\FuncCall(
                                    new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapCallable'),
                                    [
                                        new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                        new Node\Arg(new Node\Scalar\String_($paramName)),
                                        new Node\Arg(new Node\Expr\Variable($paramName)),
                                    ]
                                )
                            )
                        );
                    }
                }
            }

            // Lazy Iterable/Generator wrapping
            if (str_contains($docText, 'iterable') || str_contains($docText, 'Traversable') || str_contains($docText, 'Generator') || str_contains($docText, 'Iterator')) {
                foreach ($node->params as $param) {
                    if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $paramName = $param->var->name;
                        $injectedStmts[] = new Node\Stmt\Expression(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable($paramName),
                                new Node\Expr\FuncCall(
                                    new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
                                    [
                                        new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                        new Node\Arg(new Node\Scalar\String_($paramName)),
                                        new Node\Arg(new Node\Expr\Variable($paramName)),
                                    ]
                                )
                            )
                        );
                    }
                }
            }
        }

        if ($hasReturn) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new class ($thisArg) extends NodeVisitorAbstract {
                public function __construct(private Node $thisArg)
                {
                }

                public function enterNode(Node $n)
                {
                    if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }

                    if ($n instanceof Node\Stmt\Return_) {
                        $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));

                        $n->expr = new Node\Expr\FuncCall(
                            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
                            [
                                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                new Node\Arg($exprToWrap),
                                new Node\Arg($this->thisArg),
                                new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                            ]
                        );
                    }

                    return null;
                }
            });

            $node->stmts = $traverser->traverse($node->stmts);

            $lastStmt = end($node->stmts);
            if (! $lastStmt instanceof Node\Stmt\Return_ && ! $lastStmt instanceof Node\Stmt\Throw_) {
                $node->stmts[] = new Node\Stmt\Return_(
                    new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('null'))),
                            new Node\Arg($thisArg),
                            new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                        ]
                    )
                );
            }
        }

        if (! empty($injectedStmts)) {
            array_splice($node->stmts, 0, 0, $injectedStmts);
        }

        return null;
    }

    private function injectVarPrebinding(Node\Stmt\Expression $node)
    {
        $doc = $node->getDocComment();
        if (! $doc || ! str_contains($doc->getText(), '@var')) {
            return null;
        }

        /** @var Node\Expr\Assign $assign */
        $assign = $node->expr;
        if (! ($assign->expr instanceof Node\Expr\New_)) {
            return null;
        }

        $docText = $doc->getText();
        if (preg_match('/@var\s+([^\s$]+<[^>]+>)/', $docText, $m) || preg_match('/@var\s+\$[^\s]+\s+([^\s]+<[^>]+>)/', $docText, $m)) {
            $typeString = $m[1];

            $assign->expr = new Node\Expr\FuncCall(
                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::bindInstance'),
                [
                    new Node\Arg($assign->expr),
                    new Node\Arg(new Node\Scalar\String_($typeString)),
                ]
            );
        }

        return null;
    }
}
