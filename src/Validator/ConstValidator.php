<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Validates literal values, class constants, and PHP 8.1 Enum cases against ConstTypeNode ASTs.
 */
final class ConstValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        /** @var ConstTypeNode $constTypeNode */
        $constTypeNode = $node;
        $constExpr = $constTypeNode->constExpr;

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
        } elseif ($constExpr instanceof ConstFetchNode) {
            $fqcnConstant = $constExpr->className !== ''
                ? $constExpr->className . '::' . $constExpr->name
                : $constExpr->name;

            if (\defined($fqcnConstant)) {
                $expected = \constant($fqcnConstant);
            } else {
                $expected = (string) $constExpr;
            }
        } else {
            $expected = (string) $constExpr;
        }

        // Float Epsilon Comparison: Handles IEEE 754 precision artifacts and int-to-float coercion
        if (\is_float($expected)) {
            if ((! \is_float($value) && ! \is_int($value)) || abs((float) $value - $expected) > 1e-9) {
                return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
            }

            return null;
        }

        if ($value !== $expected) {
            return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }
}