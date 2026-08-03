<?php

declare(strict_types=1);

// -------------------------------------------------------------
// 1. Chained / Deep Type Alias Import (LevelA -> LevelB -> LevelC)
// -------------------------------------------------------------
/**
 * @phpstan-type DeepShape array{id: positive-int, score: int<1, 100>}
 */
class LevelA
{
}

/**
 * @phpstan-import-type DeepShape from LevelA
 */
class LevelB
{
}

/**
 * @phpstan-import-type DeepShape from LevelB as LocalDeepShape
 */
class LevelC
{
    /**
     * @param LocalDeepShape $payload
     */
    public function process(array $payload): bool
    {
        return true;
    }
}

// -------------------------------------------------------------
// 2. Deep Interface Method Inheritance (RootInterface -> MidInterface -> FinalExecutor)
// -------------------------------------------------------------
interface RootInterface
{
    /**
     * @param positive-int $code
     */
    public function execute(int $code): bool;
}

interface MidInterface extends RootInterface
{
}

class FinalExecutor implements MidInterface
{
    // No docblock here! Should inherit @param positive-int $code from RootInterface
    public function execute(int $code): bool
    {
        return true;
    }
}

echo "=== Testing Deep Inheritance Features ===\n\n";

// 1. Chained Imported Type Alias
echo "1. Testing Chained Imported Type Alias (LevelA -> LevelB -> LevelC)...\n";
$c = new LevelC();

try {
    $c->process(['id' => 10, 'score' => 95]);
    echo "   ✅ Valid chained DeepShape passed!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $c->process(['id' => -1, 'score' => 95]);
    echo "   ❌ Failed to catch invalid chained DeepShape!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// 2. Deep Interface Inheritance
echo "\n2. Testing Deep Interface Inheritance (RootInterface -> MidInterface -> FinalExecutor)...\n";
$executor = new FinalExecutor();

try {
    $executor->execute(100);
    echo "   ✅ Valid execute(100) passed!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $executor->execute(-50);
    echo "   ❌ Failed to catch invalid code on deep interface inheritance!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 DEEP INHERITANCE TEST COMPLETED!\n";
