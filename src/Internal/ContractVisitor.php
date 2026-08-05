<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use TypePHP\Internal\Visitor\FunctionContractInjector;
use TypePHP\Internal\Visitor\NodeBuilder;
use TypePHP\Internal\Visitor\PropertyHookInjector;
use TypePHP\Internal\Visitor\ScopeManager;

/**
 * AST Node Visitor that injects contract checks, scope tracking, property hook validation, and parameter/return wrappers into functions and methods.
 */
final class ContractVisitor extends NodeVisitorAbstract
{
    private ScopeManager $scopeManager;

    public function __construct()
    {
        $this->scopeManager = new ScopeManager();
    }

    /**
     * Traverses and transforms AST nodes during entry.
     */
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->scopeManager->pushScope();
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            FunctionContractInjector::inject($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            PropertyHookInjector::process($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Expression) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->scopeManager->extractVarDocblock($doc->getText(), $node->expr);
            }
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->scopeManager->extractVarDocblock($doc->getText(), $node->valueVar);
            }
        }

        if ($node instanceof Node\Expr\Assign) {
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $varName = $node->var->name;
                $typeString = $this->scopeManager->getVarTypeFromScope($varName);

                if ($typeString !== null) {
                    $checkCall = NodeBuilder::createVariableCheckCall($node->expr, $typeString, $varName);
                    $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall);
                }
            } elseif ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
                $propName = $node->var->name->toString();
                $objExpr = $node->var->var;

                $checkCall = NodeBuilder::createPropertyCheckCall($node->expr, $objExpr, $propName);
                $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall);
            } elseif ($node->var instanceof Node\Expr\StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
                $propName = $node->var->name->toString();
                $classExpr = $node->var->class;

                $classArg = $classExpr instanceof Node\Name
                    ? new Node\Expr\ClassConstFetch($classExpr, 'class')
                    : $classExpr;

                $checkCall = NodeBuilder::createPropertyCheckCall($node->expr, $classArg, $propName);
                $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall);
            }
        }

        return null;
    }

    /**
     * Pops the current lexical scope stack frame when leaving a function, method, or closure.
     */
    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->scopeManager->popScope();
        }

        return null;
    }
}
