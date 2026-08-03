<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

final class IntersectionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var IntersectionTypeNode $node */
        foreach ($node->types as $type) {
            if ($err = $registry->validate($value, $type, $context)) {
                return $err;
            }
        }

        return null;
    }
}
