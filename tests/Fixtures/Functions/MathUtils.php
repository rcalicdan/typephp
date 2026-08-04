<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Functions;

/**
 * @param positive-int $price
 * @param int<1, 100> $percentage
 *
 * @return positive-int
 */
function calculateDiscount(int $price, int $percentage): int
{
    $discount = (int) ($price * ($percentage / 100));

    return (int) max(1, $price - $discount);
}

/**
 * @param non-empty-string $tag
 *
 * @return non-empty-string
 */
function formatTag(string $tag): string
{
    return '#' . trim($tag);
}
