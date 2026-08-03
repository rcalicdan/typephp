<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\TypeFormatter;
use TypePHP\Validator\TypeValidatorInterface;
use TypePHP\Validator\TypeValidatorRegistry;

final class GenericValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var GenericTypeNode $genericNode */
        $genericNode = $node;
        $baseType = strtolower($genericNode->type->name);

        return match ($baseType) {
            'int', 'integer' => $this->validateIntRange($value, $genericNode, $context),
            'class-string' => $this->validateClassString($value, $genericNode, $context),
            'list', 'non-empty-list', 'non-empty-array-list' => $this->validateList($value, $genericNode, $context, $registry),
            'array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'iterator' => $this->validateArray($value, $genericNode, $context, $registry),
            default => $this->validateObjectGeneric($value, $genericNode, $context),
        };
    }

    private function validateIntRange(mixed $value, GenericTypeNode $node, string $context): ?\TypeError
    {
        if (! is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        $minNode = $node->genericTypes[0] ?? null;
        $maxNode = $node->genericTypes[1] ?? null;

        if ($minNode !== null) {
            $minStr = strtolower(trim((string) $minNode));
            if ($minStr !== 'min' && $minStr !== '*') {
                $minVal = (int) $minStr;
                if ($value < $minVal) {
                    return ErrorFactory::createError($context . " must be >= $minVal, $value given");
                }
            }
        }

        if ($maxNode !== null) {
            $maxStr = strtolower(trim((string) $maxNode));
            if ($maxStr !== 'max' && $maxStr !== '*') {
                $maxVal = (int) $maxStr;
                if ($value > $maxVal) {
                    return ErrorFactory::createError($context . " must be <= $maxVal, $value given");
                }
            }
        }

        return null;
    }

    private function validateClassString(mixed $value, GenericTypeNode $node, string $context): ?\TypeError
    {
        if (! is_string($value) || ! ClassNameValidator::isValid($value) || (! class_exists($value) && ! interface_exists($value) && ! trait_exists($value) && ! enum_exists($value))) {
            return ErrorFactory::createError($context . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        $targetClassNode = $node->genericTypes[0] ?? null;
        if ($targetClassNode instanceof IdentifierTypeNode) {
            $targetName = $targetClassNode->name;
            if (class_exists($targetName) || interface_exists($targetName) || trait_exists($targetName) || enum_exists($targetName)) {
                if (! is_a($value, $targetName, true)) {
                    return ErrorFactory::createError($context . ' must be a class-string of ' . $targetName . ", '$value' given");
                }
            }
        }

        return null;
    }

    private function validateList(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        $baseType = strtolower($node->type->name);

        if (! is_array($value) || (count($value) > 0 && ! array_is_list($value))) {
            return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        if (str_contains($baseType, 'non-empty') && count($value) === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty list, empty array given');
        }

        $valueTypeNode = $node->genericTypes[0] ?? null;
        if ($valueTypeNode !== null) {
            foreach ($value as $k => $v) {
                if ($valueTypeNode instanceof GenericTypeNode && ! in_array(strtolower($valueTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    $err = $this->validateObjectGeneric($v, $valueTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                } else {
                    $err = $registry->validate($v, $valueTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        }

        return null;
    }

    private function validateArray(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        $baseType = strtolower($node->type->name);

        if (! is_array($value) && ! ($value instanceof \Traversable)) {
            return ErrorFactory::createError($context . ' must be of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        if (! is_array($value)) {
            return null;
        }

        if (str_contains($baseType, 'non-empty') && count($value) === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty array, empty array given');
        }

        $typesCount = count($node->genericTypes);
        if ($typesCount === 1) {
            $valTypeNode = $node->genericTypes[0];
            foreach ($value as $k => $v) {
                if ($valTypeNode instanceof GenericTypeNode && ! in_array(strtolower($valTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    $err = $this->validateObjectGeneric($v, $valTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                } else {
                    $err = $registry->validate($v, $valTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        } elseif ($typesCount >= 2) {
            $keyTypeNode = $node->genericTypes[0];
            $valTypeNode = $node->genericTypes[1];
            foreach ($value as $k => $v) {
                $err = $registry->validate($k, $keyTypeNode, $context . ' key');
                if ($err !== null) {
                    return $err;
                }

                if ($valTypeNode instanceof GenericTypeNode && ! in_array(strtolower($valTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    $err = $this->validateObjectGeneric($v, $valTypeNode, $context . "['" . $k . "']");
                    if ($err !== null) {
                        return $err;
                    }
                } else {
                    $err = $registry->validate($v, $valTypeNode, $context . "['" . $k . "']");
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        }

        return null;
    }

    private function validateObjectGeneric(mixed $value, GenericTypeNode $node, string $context): ?\TypeError
    {
        if (! is_object($value)) {
            return ErrorFactory::createError($context . ' must be an object of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        if (! is_a($value, $node->type->name)) {
            return ErrorFactory::createError($context . ' must be an instance of ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return RuntimeTypeChecker::bindInstanceFromNode($value, $node, $context);
    }
}
