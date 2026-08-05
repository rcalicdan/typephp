<?php

declare(strict_types=1);

class Strings
{
    /**
     * @var string[]
     */
    public array $strings;

    public function __construct(array $strings)
    {
        $this->strings = $strings;
    }
}

new Strings(['a', 'b', 'c', 1]);
