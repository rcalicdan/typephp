<?php

declare(strict_types=1);

/**
 * @param iterable<string> $items
 */
function processStrings(iterable $items)
{
    foreach ($items as $item) {
        echo "Processing string: '$item'\n";
    }
}

/**
 * @param iterable<string, int> $map
 */
function processMap(iterable $map)
{
    foreach ($map as $key => $value) {
        echo "Processing pair: $key => $value\n";
    }
}

// 1. Fully Valid Generator
function validGenerator()
{
    yield "First";
    yield "Second";
}

// 2. Generator that yields a valid string first, then an invalid int
function invalidValueGenerator()
{
    yield "Valid String";
    yield 999; // <--- ERROR! Expected string
    yield "Third"; // Should never be reached
}

// 3. Generator that yields an invalid key type
function invalidKeyGenerator()
{
    yield "valid_key" => 10;
    yield 123 => 20; // <--- ERROR! Key must be string, got int
}

echo "=== Test 1: Valid Generator ===\n";
processStrings(validGenerator());
echo "Passed!\n\n";

echo "=== Test 2: Invalid Value Generator ===\n";
try {
    processStrings(invalidValueGenerator());
    echo "❌ FAILED: Did not catch invalid value!\n";
} catch (\TypeError $e) {
    echo "✅ CAUGHT EXPECTED ERROR: " . $e->getMessage() . "\n\n";
}

echo "=== Test 3: Invalid Key Generator ===\n";
try {
    processMap(invalidKeyGenerator());
    echo "❌ FAILED: Did not catch invalid key!\n";
} catch (\TypeError $e) {
    echo "✅ CAUGHT EXPECTED ERROR: " . $e->getMessage() . "\n\n";
}