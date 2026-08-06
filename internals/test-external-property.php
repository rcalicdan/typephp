<?php

declare(strict_types=1);

class ExternalPropTest
{
    /**
     * @var positive-int
     */
    public int $age = 10;

    /**
     * @typephp-ignore
     *
     * @var positive-int
     */
    public int $ignoredAge = 10;
}

echo "=== Testing External Property Assignments ===\n\n";

$obj = new ExternalPropTest();

// 1. Valid Assignment
echo "1. Assigning valid positive-int...\n";
$obj->age = 25;
echo "   ✅ Success! Age is now: {$obj->age}\n\n";

// 2. Ignored Property Assignment
echo "2. Assigning invalid value to @typephp-ignore property...\n";
$obj->ignoredAge = -50;
echo "   ✅ Success! Ignored property allowed -50! Value: {$obj->ignoredAge}\n\n";

// 3. Invalid Assignment (Should Throw)
echo "3. Assigning invalid value (-5) to normal property...\n";

try {
    $obj->age = -5;
    echo "   ❌ FAIL! The assignment succeeded but it should have thrown a TypeError!\n";
} catch (TypeError $e) {
    echo '   ✅ SUCCESS! Caught expected TypeError: ' . $e->getMessage() . "\n";
}
