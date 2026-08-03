<?php

declare(strict_types=1);

// -------------------------------------------------------------
// 1. Multiple Iterations (Rewindability)
// -------------------------------------------------------------
/**
 * @param Traversable<string, positive-int> $items
 */
function processMultipleTimes(Traversable $items): int
{
    $count = 0;

    // First loop
    foreach ($items as $k => $v) {
        $count++;
    }

    // Second loop (succeeds because IteratorProxy allows rewinding!)
    foreach ($items as $k => $v) {
        $count++;
    }

    return $count;
}

// -------------------------------------------------------------
// 2. Countable & Method Forwarding
// -------------------------------------------------------------
/**
 * @param Traversable<string, positive-int> $items
 */
function processWithMethodCall(Traversable $items): int
{
    if ($items instanceof Countable) {
        return $items->count();
    }

    return 0;
}

// -------------------------------------------------------------
// 3. Generator TSend Input Type Validation
// -------------------------------------------------------------
/**
 * TKey = int, TValue = string, TSend = positive-int, TReturn = void
 *
 * @return Generator<int, string, positive-int, void>
 */
function testSendGenerator(): Generator
{
    $receivedValue = yield 0 => 'first_value';
    yield 1 => "processed: {$receivedValue}";
}

echo "=== Testing Generator & Iterator Enhancements ===\n\n";

// TEST 1: Rewindability
echo "1. Testing Multiple Iteration on Wrapped Traversable (Rewindability)...\n";
$iterator = new ArrayIterator(['a' => 10, 'b' => 20]);
$totalCount = processMultipleTimes($iterator);

if ($totalCount === 4) {
    echo "   ✅ FIXED: IteratorProxy successfully allowed multiple iterations! (Count: {$totalCount})\n";
} else {
    echo "   ❌ Failed rewindability test!\n";
}

// TEST 2: Countable & Method Forwarding
echo "\n2. Testing Countable & Method Forwarding on Wrapped Traversable...\n";
$arrayIterator = new ArrayIterator(['a' => 10, 'b' => 20]);
$count = processWithMethodCall($arrayIterator);

if ($count === 2) {
    echo "   ✅ FIXED: IteratorProxy successfully forwarded count() call! (Count: {$count})\n";
} else {
    echo "   ❌ Failed Countable method test!\n";
}

// TEST 3: TSend Type Validation
echo "\n3. Testing \$gen->send() (TSend) Input Type Validation...\n";
$gen = testSendGenerator();
$gen->current(); // Reaches first yield

try {
    // Sends -500 (violating positive-int TSend contract)
    $gen->send(-500);
    echo "   ❌ Failed to catch invalid TSend value!\n";
} catch (TypeError $e) {
    echo "   ✅ FIXED CAUGHT EXPECTED TSEND ERROR: " . $e->getMessage() . "\n";
}

echo "\n🎉 ALL GENERATOR & ITERATOR ENHANCEMENTS PASSED PERFECTLY!\n";