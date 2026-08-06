<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Class for validating array shapes like array<1:string,2:int>.
 */
final class ArrayShapeValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! \is_array($value)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ArrayShapeNode $node */
        $knownKeys = [];

        foreach ($node->items as $item) {
            $key = null;
            if ($item->keyName instanceof ConstExprStringNode) {
                $key = $item->keyName->value;
            } elseif ($item->keyName !== null) {
                $key = (string) $item->keyName;
            }

            if ($key !== null) {
                $knownKeys[$key] = true;

                if (! \array_key_exists($key, $value)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " is missing required key '$key'");
                    }

                    continue;
                }

                $err = $registry->validate($value[$key], $item->valueType, $context . "['" . $key . "']");
                if ($err !== null) {
                    return $err;
                }
            }
        }

        $extraKeys = array_diff_key($value, $knownKeys);

        if (\count($extraKeys) > 0) {
            if ($node->sealed) {
                $firstExtraKey = (string) array_key_first($extraKeys);

                return ErrorFactory::createError($context . " contains unsealed unexpected key '$firstExtraKey'");
            }

            if ($node->unsealedType !== null) {
                $unsealedKeyType = $node->unsealedType->keyType;
                $unsealedValueType = $node->unsealedType->valueType;

                foreach ($extraKeys as $k => $v) {
                    if ($unsealedKeyType !== null) {
                        $err = $registry->validate($k, $unsealedKeyType, $context . " extra key '$k'");
                        if ($err !== null) {
                            return $err;
                        }
                    }
                    $err = $registry->validate($v, $unsealedValueType, $context . "['$k']");
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        }

        return null;
    }
}
