<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * 1. Simple Float Literal
 *
 * @param 12.34 $val
 */
function testSimpleFloatLiteral(float $val): float
{
    return $val;
}

/**
 * 2. Precision Sensitive Float Literal
 *
 * @param 0.3 $val
 */
function testPrecisionFloatLiteral(float $val): float
{
    return $val;
}

/**
 * 3. Float Zero Literal
 *
 * @param 0.0 $val
 */
function testFloatZeroLiteral(float $val): float
{
    return $val;
}

/**
 * 4. Integer vs Float Literal Type Strictness
 *
 * @param 10.0 $val
 */
function testFloatVsIntLiteral(mixed $val): mixed
{
    return $val;
}

echo "=== Testing Float Literal Edge Cases & Floating-Point Precision ===\n\n";

// Test 1: Simple Float Literal
echo "1. Testing Exact Float Literal (12.34)...\n";
try {
    testSimpleFloatLiteral(12.34);
    echo "   ✅ Exact float literal 12.34 passed!\n";
} catch (TypeError $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
}

try {
    testSimpleFloatLiteral(12.35);
    echo "   ❌ Failed to catch invalid float literal!\n";
} catch (TypeError $e) {
    echo "   ✅ CAUGHT EXPECTED ERROR: " . $e->getMessage() . "\n";
}

// Test 2: Floating-Point Precision (0.1 + 0.2 = 0.30000000000000004)
echo "\n2. Testing Floating-Point Arithmetic Precision (0.1 + 0.2 vs @param 0.3)...\n";
$sum = 0.1 + 0.2; // In IEEE 754 arithmetic, this evaluates to 0.30000000000000004
echo "   Computed sum (0.1 + 0.2): " . sprintf('%.17f', $sum) . "\n";

try {
    testPrecisionFloatLiteral($sum);
    echo "   ✅ Passed float precision check!\n";
} catch (TypeError $e) {
    echo "   ⚠️ CAUGHT STRICT MISMATCH (Float Precision Edge Case): " . $e->getMessage() . "\n";
}

// Test 3: Float Zero Literal (0.0 vs -0.0)
echo "\n3. Testing Float Zero (0.0 vs -0.0)...\n";
try {
    testFloatZeroLiteral(0.0);
    echo "   ✅ 0.0 passed!\n";
} catch (TypeError $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
}

try {
    testFloatZeroLiteral(-0.0);
    echo "   ✅ -0.0 passed!\n";
} catch (TypeError $e) {
    echo "   ⚠️ CAUGHT STRICT MISMATCH for -0.0: " . $e->getMessage() . "\n";
}

// Test 4: Int vs Float Strictness (10 vs 10.0)
echo "\n4. Testing Int 10 passed to @param 10.0 (Float Literal)...\n";
try {
    testFloatVsIntLiteral(10); // Passing int 10 to float literal 10.0
    echo "   ✅ Int 10 accepted for float literal 10.0!\n";
} catch (TypeError $e) {
    echo "   ⚠️ CAUGHT STRICT TYPE MISMATCH (Int 10 vs Float 10.0): " . $e->getMessage() . "\n";
}

echo "\n🎉 FLOAT LITERAL TEST COMPLETED!\n";