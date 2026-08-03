<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

final class TypeValidatorRegistry
{
    /**
     * @var array<string, TypeValidatorInterface>
     */
    private array $validators = [];

    public function __construct()
    {
        $this->validators = [
            IdentifierTypeNode::class => new IdentifierValidator(),
            GenericTypeNode::class => new GenericValidator(),
            UnionTypeNode::class => new UnionValidator(),
            IntersectionTypeNode::class => new IntersectionValidator(),
            NullableTypeNode::class => new NullableValidator(),
            ArrayTypeNode::class => new ArrayValidator(),
            ArrayShapeNode::class => new ArrayShapeValidator(),
            ConstTypeNode::class => new ConstValidator(),
        ];
    }

    public function validate(mixed $value, TypeNode $node, string $context): ?\TypeError
    {
        $validator = $this->validators[get_class($node)] ?? null;
        if ($validator === null) {
            return null; // Fallback for unhandled AST nodes
        }

        return $validator->validate($value, $node, $context, $this);
    }
}
