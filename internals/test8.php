<?php

declare(strict_types=1);

class Numbers
{
    /**
     * @param int[] $numbers
     */
    public function __construct(public array $numbers)
    {
    }
}


new Numbers(['a', 'b', 'c', 1]);