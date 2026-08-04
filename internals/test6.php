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
     * @var string[] $strings
     */
    public array $strings;

    public function __construct(array $strings)
    {
        $this->strings = $strings;
    }
}


new Strings(['a', 'b', 'c', 1]);
