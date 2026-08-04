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

/**
 * AST Node Visitor that injects contract checks, scope tracking, property hook validation, and parameter/return wrappers into functions and methods.
 */
final class ContractVisitor extends NodeVisitorAbstract
{
    /**
     * Stack storing variable types per lexical scope (global, functions, closures, methods).
     *
     * @var array<int, array<string, string>>
     */
    private array $scopeStack = [[]];

    /**
     * Traverses and transforms AST nodes during entry.
     *
     * Performs the following steps:
     * 1. Tracks lexical scope frames for variables across functions, closures, and methods.
     * 2. Injects runtime contract checks on function and method declarations.
     * 3. Intercepts PHP 8.4 Property Hooks (get and set) to validate property reads and writes.
     * 4. Extracts @var annotations from expression statements.
     * 5. Extracts @var annotations from foreach loop value variables.
     * 6. Intercepts variable assignments to apply inline type validation wrappers with native throw expressions.
     * 7. Intercepts property and static property assignments to apply validation wrappers.
     */
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->scopeStack[] = [];
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $this->injectFunctionContract($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $this->processPropertyHooks($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Expression) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->extractVarDocblock($doc->getText(), $node->expr);
            }
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->extractVarDocblock($doc->getText(), $node->valueVar);
            }
        }

        if ($node instanceof Node\Expr\Assign) {
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $varName = $node->var->name;
                $typeString = $this->getVarTypeFromScope($varName);

                if ($typeString !== null) {
                    $checkCall = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkVariable'),
                        [
                            new Node\Arg($node->expr),
                            new Node\Arg(new Node\Scalar\String_($typeString)),
                            new Node\Arg(new Node\Scalar\String_($varName)),
                            new Node\Arg(new Node\Scalar\MagicConst\File()),
                        ]
                    );

                    $this->wrapAssignWithThrow($node, $checkCall);
                }
            } elseif ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
                $propName = $node->var->name->toString();
                $objExpr = $node->var->var;

                $checkCall = $this->createPropertyCheckCall($node->expr, $objExpr, $propName);

                $this->wrapAssignWithThrow($node, $checkCall);
            } elseif ($node->var instanceof Node\Expr\StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
                $propName = $node->var->name->toString();
                $classExpr = $node->var->class;

                $classArg = $classExpr instanceof Node\Name
                    ? new Node\Expr\ClassConstFetch($classExpr, 'class')
                    : $classExpr;

                $checkCall = $this->createPropertyCheckCall($node->expr, $classArg, $propName);

                $this->wrapAssignWithThrow($node, $checkCall);
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
            array_pop($this->scopeStack);
        }

        return null;
    }

    /**
     * Creates a FunctionCall node for RuntimeTypeChecker::checkProperty().
     */
    public function createPropertyCheckCall(Node\Expr $valueExpr, Node\Expr $objectOrClassExpr, string $propertyName): Node\Expr\FuncCall
    {
        return new Node\Expr\FuncCall(
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkProperty'),
            [
                new Node\Arg($valueExpr),
                new Node\Arg($objectOrClassExpr),
                new Node\Arg(new Node\Scalar\String_($propertyName)),
                new Node\Arg(new Node\Scalar\MagicConst\File()),
            ]
        );
    }

    /**
     * Creates a ternary throw expression wrapping an ErrorMessage result.
     */
    public function createTernaryThrowExpr(Node\Expr\FuncCall $checkCall): Node\Expr\Ternary
    {
        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(
                    new Node\Expr\Variable('__typephpVal'),
                    $checkCall
                ),
                new Node\Name('\TypePHP\Internal\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Internal\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name('\TypeError'),
                                [
                                    new Node\Arg(
                                        new Node\Expr\MethodCall(
                                            new Node\Expr\Variable('__typephpVal'),
                                            'getMessage'
                                        )
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new Node\Expr\Variable('__typephpVal')
        );
    }

    /**
     * Applies the ternary throw expression wrapper around an assignment.
     */
    private function wrapAssignWithThrow(Node\Expr\Assign $node, Node\Expr\FuncCall $checkCall): void
    {
        $node->expr = $this->createTernaryThrowExpr($checkCall);
    }

    /**
     * Injects property contract validation into PHP 8.4 get and set property hooks.
     */
    private function processPropertyHooks(Node\Stmt\Property $node): void
    {
        if (empty($node->hooks)) {
            return;
        }

        $propertyName = $node->props[0]->name->toString();

        foreach ($node->hooks as $hook) {
            $hookName = strtolower($hook->name->toString());

            if ($hookName === 'get') {
                if ($hook->body instanceof Node\Expr) {
                    $checkCall = $this->createPropertyCheckCall($hook->body, new Node\Expr\Variable('this'), $propertyName);
                    $hook->body = $this->createTernaryThrowExpr($checkCall);
                } elseif (is_array($hook->body)) {
                    $hook->body = $this->wrapHookReturnStatements($hook->body, $propertyName);
                }
            } elseif ($hookName === 'set') {
                $paramName = ! empty($hook->params) && $hook->params[0]->var instanceof Node\Expr\Variable && is_string($hook->params[0]->var->name)
                    ? $hook->params[0]->var->name
                    : 'value';

                $checkCall = $this->createPropertyCheckCall(new Node\Expr\Variable($paramName), new Node\Expr\Variable('this'), $propertyName);
                $paramCheckStmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($paramName),
                        $this->createTernaryThrowExpr($checkCall)
                    )
                );

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
     * Wraps return statements inside a get property hook body.
     *
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private function wrapHookReturnStatements(array $stmts, string $propertyName): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($propertyName, $this) extends NodeVisitorAbstract {
            public function __construct(
                private string $propertyName,
                private ContractVisitor $visitor
            ) {
            }

            public function enterNode(Node $n): ?Node
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Stmt\Return_ && $n->expr !== null) {
                    $checkCall = $this->visitor->createPropertyCheckCall($n->expr, new Node\Expr\Variable('this'), $this->propertyName);
                    $n->expr = $this->visitor->createTernaryThrowExpr($checkCall);
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        return $newStmts;
    }

    /**
     * Parses @var docblock tags and records variable types into the current scope frame.
     *
     * Infers variable names from expressions or variable declarations if unnamed in the tag.
     */
    private function extractVarDocblock(string $docText, ?Node\Expr $expr = null): void
    {
        try {
            $docText = DocblockNormalizer::normalize($docText);
            [$phpDocParser, $lexer] = self::getPhpDocParserComponents();

            $tokens = new TokenIterator($lexer->tokenize($docText));
            $phpDocNode = $phpDocParser->parse($tokens);
            $varTags = $phpDocNode->getVarTagValues();

            if (\count($varTags) > 0) {
                $typeString = (string) $varTags[0]->type;
                $varName = ltrim($varTags[0]->variableName, '$');

                if ($varName === '' && $expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                    $varName = $expr->var->name;
                } elseif ($varName === '' && $expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                    $varName = $expr->name;
                }

                if ($varName !== '') {
                    $currentScopeIndex = count($this->scopeStack) - 1;
                    $this->scopeStack[$currentScopeIndex][$varName] = $typeString;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed docblocks
        }
    }

    /**
     * Resolves the recorded type of a variable by searching from the innermost scope frame outwards.
     */
    private function getVarTypeFromScope(string $varName): ?string
    {
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$varName])) {
                return $this->scopeStack[$i][$varName];
            }
        }

        return null;
    }

    /**
     * Determines if a function or class method body contains yield or yield from expressions.
     */
    private function isGenerator(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): bool
    {
        if ($node->stmts === null) {
            return false;
        }

        $visitor = new class () extends NodeVisitorAbstract {
            public bool $isGen = false;

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
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($node->stmts);

        return $visitor->isGen;
    }

    /**
     * Prepends parameter contract checks and wraps return statements for function and method declarations.
     */
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
            $injectedStmts = $this->buildParamInjections($node, $docText, $thisArg, $isClassMethod);
        }

        if ($hasReturn) {
            $node->stmts = $this->isGenerator($node)
                ? $this->wrapGeneratorReturns($node->stmts)
                : $this->wrapNonGeneratorReturns($node->stmts, $thisArg, $isNativeVoid);
        }

        $node->stmts = array_merge($injectedStmts, $node->stmts);
    }

    /**
     * Builds parameter validation and wrapper statements.
     *
     * Injects:
     * - Single-level IF statement to initialize scope and evaluate parameter constraints without line drift.
     * - Callable parameter wrappers for runtime callback checks.
     * - Lazy iterable and generator parameter wrappers.
     *
     * @return array<Node\Stmt>
     */
    private function buildParamInjections(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        string $docText,
        Node\Expr $thisArg,
        bool $isClassMethod
    ): array {
        $injectedStmts = [];

        $injectedStmts[] = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(
                    new Node\Expr\Variable('__typephpErr'),
                    new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::setupScope'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                            new Node\Arg($thisArg),
                        ]
                    )
                ),
                new Node\Name('\TypePHP\Internal\ErrorMessage')
            ),
            [
                'stmts' => [
                    new Node\Stmt\Expression(
                        new Node\Expr\Throw_(
                            new Node\Expr\StaticCall(
                                new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                'prepareException',
                                [
                                    new Node\Arg(
                                        new Node\Expr\New_(
                                            new Node\Name('\TypeError'),
                                            [
                                                new Node\Arg(
                                                    new Node\Expr\MethodCall(
                                                        new Node\Expr\Variable('__typephpErr'),
                                                        'getMessage'
                                                    )
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        )
                    ),
                ],
            ]
        );

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

        return $injectedStmts;
    }

    /**
     * Wraps yield/yield from expressions lazily for generator functions.
     *
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private function wrapGeneratorReturns(array $stmts): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class () extends NodeVisitorAbstract {
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

                    $checkYieldCall = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkYield'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg($n->key ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                            new Node\Arg($n->value ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                        ]
                    );

                    $n->value = new Node\Expr\Ternary(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(new Node\Expr\Variable('__typephpYld'), $checkYieldCall),
                            new Node\Name('\TypePHP\Internal\ErrorMessage')
                        ),
                        new Node\Expr\Throw_(
                            new Node\Expr\StaticCall(
                                new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                'prepareException',
                                [
                                    new Node\Arg(
                                        new Node\Expr\New_(
                                            new Node\Name('\TypeError'),
                                            [
                                                new Node\Arg(
                                                    new Node\Expr\MethodCall(new Node\Expr\Variable('__typephpYld'), 'getMessage')
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new Node\Expr\Variable('__typephpYld')
                    );

                    $checkSendCall = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkSend'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg($n),
                        ]
                    );

                    return new Node\Expr\Ternary(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(new Node\Expr\Variable('__typephpSnd'), $checkSendCall),
                            new Node\Name('\TypePHP\Internal\ErrorMessage')
                        ),
                        new Node\Expr\Throw_(
                            new Node\Expr\StaticCall(
                                new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                'prepareException',
                                [
                                    new Node\Arg(
                                        new Node\Expr\New_(
                                            new Node\Name('\TypeError'),
                                            [
                                                new Node\Arg(
                                                    new Node\Expr\MethodCall(new Node\Expr\Variable('__typephpSnd'), 'getMessage')
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new Node\Expr\Variable('__typephpSnd')
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
        $newStmts = $traverser->traverse($stmts);

        return $newStmts;
    }

    /**
     * Wraps return statements and injects trailing return checks for non-generator functions.
     *
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private function wrapNonGeneratorReturns(array $stmts, Node\Expr $thisArg, bool $isNativeVoid): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($thisArg, $isNativeVoid) extends NodeVisitorAbstract {
            public function __construct(
                private Node\Expr $thisArg,
                private bool $isNativeVoid
            ) {
            }

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
                            new Node\Stmt\If_(
                                new Node\Expr\Instanceof_(
                                    new Node\Expr\Assign(
                                        new Node\Expr\Variable('__typephpRet'),
                                        $checkReturnCall
                                    ),
                                    new Node\Name('\TypePHP\Internal\ErrorMessage')
                                ),
                                [
                                    'stmts' => [
                                        new Node\Stmt\Expression(
                                            new Node\Expr\Throw_(
                                                new Node\Expr\StaticCall(
                                                    new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                                    'prepareException',
                                                    [
                                                        new Node\Arg(
                                                            new Node\Expr\New_(
                                                                new Node\Name('\TypeError'),
                                                                [
                                                                    new Node\Arg(
                                                                        new Node\Expr\MethodCall(
                                                                            new Node\Expr\Variable('__typephpRet'),
                                                                            'getMessage'
                                                                        )
                                                                    ),
                                                                ]
                                                            )
                                                        ),
                                                    ]
                                                )
                                            )
                                        ),
                                    ],
                                ]
                            ),
                            new Node\Stmt\Return_(null),
                        ];
                    }

                    $n->expr = new Node\Expr\Ternary(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable('__typephpRet'),
                                $checkReturnCall
                            ),
                            new Node\Name('\TypePHP\Internal\ErrorMessage')
                        ),
                        new Node\Expr\Throw_(
                            new Node\Expr\StaticCall(
                                new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                'prepareException',
                                [
                                    new Node\Arg(
                                        new Node\Expr\New_(
                                            new Node\Name('\TypeError'),
                                            [
                                                new Node\Arg(
                                                    new Node\Expr\MethodCall(
                                                        new Node\Expr\Variable('__typephpRet'),
                                                        'getMessage'
                                                    )
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new Node\Expr\Variable('__typephpRet')
                    );
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        $lastStmt = end($newStmts);
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
                $newStmts[] = new Node\Stmt\If_(
                    new Node\Expr\Instanceof_(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable('__typephpRet'),
                            $checkReturnCall
                        ),
                        new Node\Name('\TypePHP\Internal\ErrorMessage')
                    ),
                    [
                        'stmts' => [
                            new Node\Stmt\Expression(
                                new Node\Expr\Throw_(
                                    new Node\Expr\StaticCall(
                                        new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                        'prepareException',
                                        [
                                            new Node\Arg(
                                                new Node\Expr\New_(
                                                    new Node\Name('\TypeError'),
                                                    [
                                                        new Node\Arg(
                                                            new Node\Expr\MethodCall(
                                                                new Node\Expr\Variable('__typephpRet'),
                                                                'getMessage'
                                                            )
                                                        ),
                                                    ]
                                                )
                                            ),
                                        ]
                                    )
                                )
                            ),
                        ],
                    ]
                );
                $newStmts[] = new Node\Stmt\Return_(null);
            } else {
                $newStmts[] = new Node\Stmt\Return_(
                    new Node\Expr\Ternary(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable('__typephpRet'),
                                $checkReturnCall
                            ),
                            new Node\Name('\TypePHP\Internal\ErrorMessage')
                        ),
                        new Node\Expr\Throw_(
                            new Node\Expr\StaticCall(
                                new Node\Name('\TypePHP\Internal\ErrorFactory'),
                                'prepareException',
                                [
                                    new Node\Arg(
                                        new Node\Expr\New_(
                                            new Node\Name('\TypeError'),
                                            [
                                                new Node\Arg(
                                                    new Node\Expr\MethodCall(
                                                        new Node\Expr\Variable('__typephpRet'),
                                                        'getMessage'
                                                    )
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new Node\Expr\Variable('__typephpRet')
                    )
                );
            }
        }

        return $newStmts;
    }

    /**
     * Returns shared static instances of PHPStan's PhpDocParser and Lexer.
     *
     * @return array{PhpDocParser, Lexer}
     */
    private static function getPhpDocParserComponents(): array
    {
        /** @var PhpDocParser|null $phpDocParser */
        static $phpDocParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($phpDocParser === null || $lexer === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        }

        return [$phpDocParser, $lexer];
    }
}
