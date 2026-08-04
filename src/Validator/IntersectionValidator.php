<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

final class IntersectionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?\TypeError
    {
        /** @var IntersectionTypeNode $intersectionNode */
        $intersectionNode = $node;

        foreach ($intersectionNode->types as $type) {
            $err = $registry->validate($value, $type, $context);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }
}
