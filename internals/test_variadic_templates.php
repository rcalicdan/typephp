<?php

declare(strict_types=1);

/**
 * @template T
 *
 * @param T ...$items
 *
 * @return T[]
 */
function collectSameType(...$items): array
{
    return $items;
}

echo "=== Testing Variadic Generic Templates (@template T ...\$items) ===\n\n";

// 1. Valid: All arguments are integers -> T is inferred as int
echo "1. Testing valid variadic template (all ints)...\n";
collectSameType(10, 20, 30);
echo "   ✅ Valid variadic template passed! (Inferred T = int)\n";

// 2. Invalid: First arg is int, 3rd arg is string -> Throws TypeError!
echo "\n2. Testing invalid variadic template (mixed int and string)...\n";

try {
    collectSameType(10, 20, 'invalid_string');
    echo "   ❌ Failed to catch inconsistent variadic template type!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 VARIADIC GENERICS TEST PASSED PERFECTLY!\n";
