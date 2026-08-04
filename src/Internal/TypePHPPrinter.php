<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/**
 * Custom AST Printer that squashes injected TypePHP validation blocks into a single line
 * without adding trailing newlines to guarantee zero line-number drift.
 */
final class TypePHPPrinter extends Standard
{
    /**
     * Overrides statement array printing to avoid adding newlines after inline TypePHP statements.
     *
     * @param array<Stmt> $nodes
     */
    protected function pStmts(array $nodes, bool $trailingNewline = true): string
    {
        $result = '';
        foreach ($nodes as $node) {
            $comments = $node->getAttribute('comments', []);
            if ($comments) {
                $result .= $this->pComments($comments);
            }

            $pNode = $this->p($node);

            if ($node->getAttribute('typephp_no_newline') === true || str_contains($pNode, 'RuntimeTypeChecker::setupScope')) {
                $result .= $pNode . ' ';
            } else {
                $result .= $pNode . $this->nl;
            }
        }

        if (! $trailingNewline && str_ends_with($result, $this->nl)) {
            $result = substr($result, 0, -strlen($this->nl));
        }

        return $result;
    }

    /**
     * Overrides the printing of If_ statements to squash setupScope blocks onto a single line.
     */
    protected function pStmt_If(Stmt\If_ $node): string
    {
        $output = parent::pStmt_If($node);

        if ($node->getAttribute('typephp_no_newline') === true || str_contains($output, 'RuntimeTypeChecker::setupScope')) {
            return preg_replace('/\s+/', ' ', trim($output)) ?? $output;
        }

        return $output;
    }
}