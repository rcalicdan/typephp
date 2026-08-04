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

class Strings
{
    /**
     * @param string[] $strings
     */
    public array $strings;

    /**
     * @param string[] $strings
    */
    public function __construct(array $strings)
    {
        $this->strings = $strings;
    }
}

// new Numbers([1, 2, 3, 4, '5']);
new Strings(['a', 'b', 'c', 1]);
