<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
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

                if ($err = $registry->validate($value[$key], $item->valueType, $context . "['" . $key . "']")) {
                    return $err;
                }
            }
        }

        $extraKeys = array_diff_key($value, $knownKeys);

        if (! empty($extraKeys)) {
            if ($node->sealed) {
                $firstExtraKey = array_key_first($extraKeys);

                return ErrorFactory::createError($context . " contains unsealed unexpected key '$firstExtraKey'");
            }

            if ($node->unsealedType !== null) {
                $unsealedKeyType = $node->unsealedType->keyType;
                $unsealedValueType = $node->unsealedType->valueType;

                foreach ($extraKeys as $k => $v) {
                    if ($unsealedKeyType !== null) {
                        if ($err = $registry->validate($k, $unsealedKeyType, $context . " extra key '$k'")) {
                            return $err;
                        }
                    }
                    if ($err = $registry->validate($v, $unsealedValueType, $context . "['$k']")) {
                        return $err;
                    }
                }
            }
        }

        return null;
    }
}
