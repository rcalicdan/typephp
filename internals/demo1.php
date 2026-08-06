<?php

declare(strict_types=1);

echo "--- Test 6a: @var inside an if-block, does it leak to outer scope? ---\n";

function testIfBlockScope(bool $flag): void
{
    if ($flag) {
        /** @var positive-int $x */
        $x = 10;
        echo "inside if: x = $x\n";
    }

    try {
        $x = -5;
        echo "outside if: x = $x (no throw means scope did NOT leak)\n";
    } catch (TypeError $e) {
        echo "outside if: threw — {$e->getMessage()}\n";
    }
}

testIfBlockScope(true);

echo "\n--- Test 6b: @var inside a closure, does it leak to the enclosing function? ---\n";

function testClosureScope(): void
{
    $fn = function () {
        /** @var positive-int $y */
        $y = 10;
        echo "inside closure: y = $y\n";
    };
    $fn();

    try {
        $y = -5;
        echo "outside closure: y = $y (no throw means scope did NOT leak)\n";
    } catch (TypeError $e) {
        echo "outside closure: threw — {$e->getMessage()}\n";
    }
}

testClosureScope();

echo "\n--- Test 6c: @var re-declared in a nested if with a DIFFERENT type ---\n";

function testShadowedType(bool $flag): void
{
    /** @var positive-int $z */
    $z = 10;

    if ($flag) {
        /** @var non-empty-string $z */
        $z = 'hello';
        echo "inside if: z = $z\n";
    }

    try {
        $z = -5;
        echo "outside if: z = $z (assignment succeeded, outer contract = ?)\n";
    } catch (TypeError $e) {
        echo "outside if: threw — {$e->getMessage()}\n";
    }
}

testShadowedType(true);
