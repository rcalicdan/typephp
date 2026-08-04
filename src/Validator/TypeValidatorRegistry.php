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
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ErrorMessage;

/**
 * Registry mapping AST TypeNodes to their corresponding validator strategy implementations.
 */
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
            ObjectShapeNode::class => new ObjectShapeValidator(),
            ConstTypeNode::class => new ConstValidator(),
        ];
    }

    /**
     * Validates a value against an AST TypeNode and returns an ErrorMessage on failure or null on success.
     */
    public function validate(mixed $value, TypeNode $node, string $context): ?ErrorMessage
    {
        $validator = $this->validators[get_class($node)] ?? null;
        if ($validator === null) {
            return null;
        }

        return $validator->validate($value, $node, $context, $this);
    }
}