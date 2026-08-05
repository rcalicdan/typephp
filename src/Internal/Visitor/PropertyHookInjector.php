<?php

declare(strict_types=1);

namespace TypePHP\Internal\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Injects property contract checks into PHP 8.4 get and set property hooks.
 */
final class PropertyHookInjector
{
    public static function process(Node\Stmt\Property $node): void
    {
        if (empty($node->hooks)) {
            return;
        }

        $propertyName = $node->props[0]->name->toString();

        foreach ($node->hooks as $hook) {
            $hookName = strtolower($hook->name->toString());

            if ($hookName === 'get') {
                if ($hook->body instanceof Node\Expr) {
                    $checkCall = NodeBuilder::createPropertyCheckCall($hook->body, new Node\Expr\Variable('this'), $propertyName);
                    $hook->body = NodeBuilder::createTernaryThrowExpr($checkCall);
                } elseif (is_array($hook->body)) {
                    $hook->body = self::wrapHookReturnStatements($hook->body, $propertyName);
                }
            } elseif ($hookName === 'set') {
                $paramName = ! empty($hook->params) && $hook->params[0]->var instanceof Node\Expr\Variable && is_string($hook->params[0]->var->name)
                    ? $hook->params[0]->var->name
                    : 'value';

                $checkCall = NodeBuilder::createPropertyCheckCall(new Node\Expr\Variable($paramName), new Node\Expr\Variable('this'), $propertyName);
                $paramCheckStmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($paramName),
                        NodeBuilder::createTernaryThrowExpr($checkCall)
                    )
                );
                $paramCheckStmt->setAttribute('typephp_injected', true);

                if (is_array($hook->body)) {
                    array_unshift($hook->body, $paramCheckStmt);
                } elseif ($hook->body instanceof Node\Expr) {
                    $hook->body = [
                        $paramCheckStmt,
                        new Node\Stmt\Expression($hook->body),
                    ];
                }
            }
        }
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private static function wrapHookReturnStatements(array $stmts, string $propertyName): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($propertyName) extends NodeVisitorAbstract {
            public function __construct(private string $propertyName)
            {
            }

            public function enterNode(Node $n): ?Node
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Stmt\Return_ && $n->expr !== null) {
                    $checkCall = NodeBuilder::createPropertyCheckCall($n->expr, new Node\Expr\Variable('this'), $this->propertyName);
                    $n->expr = NodeBuilder::createTernaryThrowExpr($checkCall);
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        return $newStmts;
    }
}
