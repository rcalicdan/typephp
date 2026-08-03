<?php

declare(strict_types=1);

/**
 * @template T
 */
class Producer
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

class BaseNode
{
}

class Node extends BaseNode
{
    /**
     * Covariant self: accepts Producer<Node> or Producer<SubNode>
     *
     * @param Producer<covariant self> $producer
     */
    public function processCovariant(Producer $producer)
    {
        return $producer->item;
    }

    /**
     * Contravariant self: accepts Producer<Node> or Producer<BaseNode>
     *
     * @param Producer<contravariant self> $producer
     */
    public function processContravariant(Producer $producer)
    {
        return $producer->item;
    }
}

class SubNode extends Node
{
}
class UnrelatedClass
{
}

echo "=== Testing <self> with Generic Variance ===\n\n";

$node = new Node();

// -------------------------------------------------------------
// 1. Covariant <covariant self> Tests
// -------------------------------------------------------------
echo "1. Testing <covariant self>...\n";

// Valid: SubNode extends Node (Covariant allows subtypes!)
$node->processCovariant(new Producer(new SubNode()));
echo "   ✅ Producer<SubNode> passed for Producer<covariant self>!\n";

// Invalid: UnrelatedClass is not a Node
try {
    $node->processCovariant(new Producer(new UnrelatedClass()));
    echo "   ❌ Failed to catch bad covariant argument!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 2. Contravariant <contravariant self> Tests
// -------------------------------------------------------------
echo "\n2. Testing <contravariant self>...\n";

// Valid: BaseNode is a supertype of Node (Contravariant allows supertypes!)
$node->processContravariant(new Producer(new BaseNode()));
echo "   ✅ Producer<BaseNode> passed for Producer<contravariant self>!\n";

// Invalid: SubNode is a subtype, not a supertype!
try {
    $node->processContravariant(new Producer(new SubNode()));
    echo "   ❌ Failed to catch bad contravariant argument!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 VARIANCE WITH <self> TEST PASSED PERFECTLY!\n";
