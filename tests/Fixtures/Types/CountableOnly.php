<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

use Countable;

/**
 * Class implementing Countable only for testing intersection type failures.
 */
class CountableOnly implements Countable
{
    public function count(): int
    {
        return 0;
    }
}
