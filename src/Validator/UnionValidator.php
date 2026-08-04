<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\TypeFormatter;

final class UnionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var UnionTypeNode $unionNode */
        $unionNode = $node;

        foreach ($unionNode->types as $type) {
            if ($registry->validate($value, $type, $context) === null) {
                return null;
            }
        }

        return ErrorFactory::createError($context . ' must be of type ' . $unionNode . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
    }
}
