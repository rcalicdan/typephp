<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

final class ContractVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?int
    {
        // Function / Method contract injection
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $this->injectFunctionContract($node);

            return null;
        }

        // @var Docblock Pre-binding on assignments ($var = new Class())
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
            $this->injectVarPrebinding($node);

            return null;
        }

        return null;
    }

    private function isGenerator(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): bool
    {
        if ($node->stmts === null) {
            return false;
        }

        $isGen = false;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($isGen) extends NodeVisitorAbstract {
            public function __construct(private bool &$isGen) {}

            public function enterNode(Node $n): ?int
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Expr\Yield_ || $n instanceof Node\Expr\YieldFrom) {
                    $this->isGen = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        });

        $traverser->traverse($node->stmts);

        return $isGen;
    }

    private function injectFunctionContract(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): void
    {
        if ($node->stmts === null) {
            return;
        }

        $isClassMethod = $node instanceof Node\Stmt\ClassMethod;
        $doc = $node->getDocComment();

        if ($doc === null && ! $isClassMethod) {
            return;
        }

        $docText = $doc !== null ? $doc->getText() : '';
        $hasParam = $isClassMethod || str_contains($docText, '@param');
        $hasReturn = $isClassMethod || str_contains($docText, '@return') || str_contains($docText, '@phpstan-return') || str_contains($docText, '@psalm-return');

        if (! $hasParam && ! $hasReturn) {
            return;
        }

        $isNativeVoid = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'void';

        $hasThis = $isClassMethod && ! $node->isStatic();
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
            if ($isClassMethod || str_contains($docText, 'callable') || str_contains($docText, 'Closure')) {
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
            if ($isClassMethod || str_contains($docText, 'iterable') || str_contains($docText, 'Traversable') || str_contains($docText, 'Generator') || str_contains($docText, 'Iterator')) {
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
            $isGeneratorFunc = $this->isGenerator($node);

            if ($isGeneratorFunc) {
                // For generator functions, wrap yield/yield from expressions lazily
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new class() extends NodeVisitorAbstract {
                    public function enterNode(Node $n): int|Node|null
                    {
                        if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }

                        if ($n instanceof Node\Expr\Yield_) {
                            if ($n->getAttribute('typephp_wrapped') === true) {
                                return null;
                            }

                            $n->setAttribute('typephp_wrapped', true);

                            $n->value = new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkYield'),
                                [
                                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                    new Node\Arg($n->key ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                                    new Node\Arg($n->value ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                                ]
                            );

                            return new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkSend'),
                                [
                                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                    new Node\Arg($n),
                                ]
                            );
                        }

                        if ($n instanceof Node\Expr\YieldFrom) {
                            if ($n->getAttribute('typephp_wrapped') === true) {
                                return null;
                            }

                            $n->setAttribute('typephp_wrapped', true);

                            $n->expr = new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
                                [
                                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                    new Node\Arg(new Node\Scalar\String_('return')),
                                    new Node\Arg($n->expr),
                                ]
                            );
                        }

                        return null;
                    }
                });

                /** @var array<Node\Stmt> $newStmts */
                $newStmts = $traverser->traverse($node->stmts);
                $node->stmts = $newStmts;
            } else {
                // Non-generator functions: wrap return statements
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new class($thisArg, $isNativeVoid) extends NodeVisitorAbstract {
                    public function __construct(
                        private Node\Expr $thisArg,
                        private bool $isNativeVoid
                    ) {}

                    public function enterNode(Node $n): int|array|null
                    {
                        if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }

                        if ($n instanceof Node\Stmt\Return_) {
                            $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));

                            $checkReturnCall = new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
                                [
                                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                    new Node\Arg($exprToWrap),
                                    new Node\Arg($this->thisArg),
                                    new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                                ]
                            );

                            if ($this->isNativeVoid) {
                                return [
                                    new Node\Stmt\Expression($checkReturnCall),
                                    new Node\Stmt\Return_(null),
                                ];
                            }

                            $n->expr = $checkReturnCall;
                        }

                        return null;
                    }
                });

                /** @var array<Node\Stmt> $newStmts */
                $newStmts = $traverser->traverse($node->stmts);
                $node->stmts = $newStmts;

                $lastStmt = end($node->stmts);
                if (! $lastStmt instanceof Node\Stmt\Return_ && ! ($lastStmt instanceof Node\Stmt\Expression && $lastStmt->expr instanceof Node\Expr\Throw_)) {
                    $checkReturnCall = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('null'))),
                            new Node\Arg($thisArg),
                            new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                        ]
                    );

                    if ($isNativeVoid) {
                        $node->stmts[] = new Node\Stmt\Expression($checkReturnCall);
                        $node->stmts[] = new Node\Stmt\Return_(null);
                    } else {
                        $node->stmts[] = new Node\Stmt\Return_($checkReturnCall);
                    }
                }
            }
        }

        $allStmts = [...$injectedStmts, ...$node->stmts];

        if (\count($allStmts) > 0) {
            $popCallFrameStmt = new Node\Stmt\Expression(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Resolver\TemplateManager'),
                    'popCallFrame',
                    [new Node\Arg(new Node\Scalar\MagicConst\Method())]
                )
            );

            $tryFinallyStmt = new Node\Stmt\TryCatch(
                $allStmts,
                [],
                new Node\Stmt\Finally_([$popCallFrameStmt])
            );

            $node->stmts = [$tryFinallyStmt];
        }
    }


    private function injectVarPrebinding(Node\Stmt\Expression $node): void
    {
        $doc = $node->getDocComment();
        if ($doc === null || ! str_contains($doc->getText(), '@var')) {
            return;
        }

        /** @var Node\Expr\Assign $assign */
        $assign = $node->expr;

        static $phpDocParser = null;
        static $lexer = null;

        if ($phpDocParser === null || $lexer === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        }

        try {
            $tokens = new TokenIterator($lexer->tokenize($doc->getText()));
            $phpDocNode = $phpDocParser->parse($tokens);
            $varTags = $phpDocNode->getVarTagValues();

            if (\count($varTags) > 0) {
                $typeString = (string) $varTags[0]->type;
                $varName = ltrim($varTags[0]->variableName, '$');

                if ($varName === '' && $assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                    $varName = $assign->var->name;
                }

                if ($varTags[0]->type instanceof \PHPStan\PhpDocParser\Ast\Type\CallableTypeNode) {
                    $assign->expr = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapCallableInline'),
                        [
                            new Node\Arg($assign->expr),
                            new Node\Arg(new Node\Scalar\String_($typeString)),
                            new Node\Arg(new Node\Scalar\String_($varName)),
                            new Node\Arg(new Node\Scalar\MagicConst\File()),
                        ]
                    );

                    return;
                }

                if ($assign->expr instanceof Node\Expr\New_ && str_contains($typeString, '<')) {
                    $assign->expr = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::bindInstance'),
                        [
                            new Node\Arg($assign->expr),
                            new Node\Arg(new Node\Scalar\String_($typeString)),
                            new Node\Arg(new Node\Scalar\MagicConst\File()),
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed docblocks
        }
    }
}