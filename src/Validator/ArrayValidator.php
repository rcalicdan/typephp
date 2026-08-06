<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Class for validating array types like array<int, string>.
 */
final class ArrayValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! is_array($value)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ArrayTypeNode $arrayNode */
        $arrayNode = $node;

        foreach ($value as $k => $v) {
            $err = $registry->validate($v, $arrayNode->type, $context . '[' . $k . ']');
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }
}
