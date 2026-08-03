<?php

declare(strict_types=1);

/**
 * @param positive-int $a
 * @param negative-int $b
 * @param non-positive-int $c
 * @param non-negative-int $d
 * @param non-zero-int $e
 * @param int<0, 100> $f
 * @param int<min, 100> $g
 * @param int<50, max> $h
 */
function testIntegerTypes(
    int $a,
    int $b,
    int $c,
    int $d,
    int $e,
    int $f,
    int $g,
    int $h
) {}


echo "=== Testing All 8 Integer Formats ===\n\n";

// Valid call
testIntegerTypes(
    10,      // positive-int (> 0)
    -5,      // negative-int (< 0)
    0,       // non-positive-int (<= 0)
    0,       // non-negative-int (>= 0)
    100,     // non-zero-int (!= 0)
    50,      // int<0, 100>
    -9999,   // int<min, 100>
    9999     // int<50, max>
);
echo "✅ All valid integer types passed!\n\n";

// Test failure 1: 0 passed to positive-int
try {
    testIntegerTypes(0, -5, 0, 0, 100, 50, -9999, 9999);
    echo "❌ Failed to catch 0 in positive-int!\n";
} catch (\TypeError $e) {
    echo "✅ Caught bad positive-int: " . $e->getMessage() . "\n";
}

// Test failure 2: 150 passed to int<0, 100>
try {
    testIntegerTypes(10, -5, 0, 0, 100, 150, -9999, 9999);
    echo "❌ Failed to catch 150 in int<0, 100>!\n";
} catch (\TypeError $e) {
    echo "✅ Caught bad int<0, 100>: " . $e->getMessage() . "\n";
}

// Test failure 3: 200 passed to int<min, 100>
try {
    testIntegerTypes(10, -5, 0, 0, 100, 50, 200, 9999);
    echo "❌ Failed to catch 200 in int<min, 100>!\n";
} catch (\TypeError $e) {
    echo "✅ Caught bad int<min, 100>: " . $e->getMessage() . "\n";
}

// Test failure 4: 10 passed to int<50, max>
try {
    testIntegerTypes(10, -5, 0, 0, 100, 50, -9999, 10);
    echo "❌ Failed to catch 10 in int<50, max>!\n";
} catch (\TypeError $e) {
    echo "✅ Caught bad int<50, max>: " . $e->getMessage() . "\n";
}