<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\ErrorFactory;
use TypePHP\RuntimeTypeChecker;
use TypePHP\TypeFormatter;

final class GenericValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var GenericTypeNode $node */
        $baseType = strtolower($node->type->name);

        return match ($baseType) {
            'class-string' => $this->validateClassString($value, $node, $context),
            'list' => $this->validateList($value, $node, $context, $registry),
            'array', 'iterable', 'traversable', 'generator', 'iterator' => $this->validateArray($value, $node, $context, $registry),
            default => $this->validateObjectGeneric($value, $node, $context),
        };
    }

    private function validateClassString(mixed $value, GenericTypeNode $node, string $context): ?\TypeError
    {
        if (! is_string($value) || (! class_exists($value) && ! interface_exists($value) && ! trait_exists($value) && ! enum_exists($value))) {
            return ErrorFactory::createError($context . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }
        $targetClassNode = $node->genericTypes[0] ?? null;
        if ($targetClassNode instanceof IdentifierTypeNode) {
            if (! is_a($value, $targetClassNode->name, true)) {
                return ErrorFactory::createError($context . ' must be a class-string of ' . $targetClassNode->name . ", '$value' given");
            }
        }

        return null;
    }

    private function validateList(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        if (! is_array($value) || (! empty($value) && ! array_is_list($value))) {
            return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }
        $valueTypeNode = $node->genericTypes[0] ?? null;
        if ($valueTypeNode) {
            foreach ($value as $k => $v) {
                if ($valueTypeNode instanceof GenericTypeNode && ! in_array(strtolower($valueTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    if ($err = $this->validateObjectGeneric($v, $valueTypeNode, $context . '[' . $k . ']')) {
                        return $err;
                    }
                } elseif ($err = $registry->validate($v, $valueTypeNode, $context . '[' . $k . ']')) {
                    return $err;
                }
            }
        }

        return null;
    }

    private function validateArray(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        if (! is_array($value) && ! ($value instanceof \Traversable)) {
            return ErrorFactory::createError($context . ' must be of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        // Do not iterate non-array Traversables/Generators during upfront validation.
        // They are lazily validated item-by-item by RuntimeTypeChecker::wrapIterable().
        if (! is_array($value)) {
            return null;
        }

        $typesCount = count($node->genericTypes);
        if ($typesCount === 1) {
            $valTypeNode = $node->genericTypes[0];
            foreach ($value as $k => $v) {
                if ($valTypeNode instanceof GenericTypeNode && ! in_array(strtolower($valTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    if ($err = $this->validateObjectGeneric($v, $valTypeNode, $context . '[' . $k . ']')) {
                        return $err;
                    }
                } elseif ($err = $registry->validate($v, $valTypeNode, $context . '[' . $k . ']')) {
                    return $err;
                }
            }
        } elseif ($typesCount >= 2) {
            $keyTypeNode = $node->genericTypes[0];
            $valTypeNode = $node->genericTypes[1];
            foreach ($value as $k => $v) {
                if ($err = $registry->validate($k, $keyTypeNode, $context . ' key')) {
                    return $err;
                }

                if ($valTypeNode instanceof GenericTypeNode && ! in_array(strtolower($valTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    if ($err = $this->validateObjectGeneric($v, $valTypeNode, $context . "['" . $k . "']")) {
                        return $err;
                    }
                } elseif ($err = $registry->validate($v, $valTypeNode, $context . "['" . $k . "']")) {
                    return $err;
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