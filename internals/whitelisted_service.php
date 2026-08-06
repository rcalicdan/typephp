<?php

declare(strict_types=1);

/**
 * Whitelisted Function - Transformed and Type-Checked by TypePHP
 *
 * @param positive-int $amount
 *
 * @return non-empty-string
 */
function processPayment(int $amount): string
{
    return "paid_{$amount}";
}
