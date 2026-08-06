<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Class for validating union types like int | string.
 */
final class UnionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
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
