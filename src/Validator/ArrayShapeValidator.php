<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\ErrorFactory;
use TypePHP\TypeFormatter;

final class ArrayShapeValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        if (! \is_array($value)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ArrayShapeNode $node */
        foreach ($node->items as $item) {
            $key = $item->keyName ? (string) $item->keyName : null;
            if ($key !== null) {
                if (! \array_key_exists($key, $value)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " is missing required key '$key'");
                    }

                    continue;
                }
                if ($err = $registry->validate($value[$key], $item->valueType, $context . "['" . $key . "']")) {
                    return $err;
                }
            }
        }

        return null;
    }
}
