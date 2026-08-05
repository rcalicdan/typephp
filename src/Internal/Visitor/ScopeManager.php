<?php

declare(strict_types=1);

namespace TypePHP\Internal\Visitor;

use PhpParser\Node;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use TypePHP\Contract\DocblockExtractor;
use TypePHP\Internal\DocblockNormalizer;

/**
 * Manages lexical scope stack frames and extracts local @var variable annotations.
 */
final class ScopeManager
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $scopeStack = [[]];

    public function pushScope(): void
    {
        $this->scopeStack[] = [];
    }

    public function popScope(): void
    {
        array_pop($this->scopeStack);
    }

    public function extractVarDocblock(string $docText, ?Node\Expr $expr = null): void
    {
        try {
            $docText = DocblockNormalizer::normalize($docText);
            [$phpDocParser, $lexer] = DocblockExtractor::getParserComponents();

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

    public function getVarTypeFromScope(string $varName): ?string
    {
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$varName])) {
                return $this->scopeStack[$i][$varName];
            }
        }

        return null;
    }
}
