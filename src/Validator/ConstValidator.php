<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\ErrorFactory;
use TypePHP\TypeFormatter;

final class ConstValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var ConstTypeNode $node */
        $expected = (string) $node->constExpr;
        if ((string) $value !== $expected) {
            return ErrorFactory::createError($context . ' must be literal ' . $expected . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }
}
