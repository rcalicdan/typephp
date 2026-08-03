<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\ErrorFactory;
use TypePHP\TypeFormatter;

final class ConstValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var ConstTypeNode $node */
        $constExpr = $node->constExpr;

        if ($constExpr instanceof ConstExprStringNode) {
            $expected = $constExpr->value;
        } elseif ($constExpr instanceof ConstExprTrueNode) {
            $expected = true;
        } elseif ($constExpr instanceof ConstExprFalseNode) {
            $expected = false;
        } elseif ($constExpr instanceof ConstExprNullNode) {
            $expected = null;
        } elseif ($constExpr instanceof ConstExprIntegerNode) {
            $expected = (int) $constExpr->value;
        } elseif ($constExpr instanceof ConstExprFloatNode) {
            $expected = (float) $constExpr->value;
        } else {
            $expected = (string) $constExpr;
        }

        if ($value !== $expected) {
            return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }
}