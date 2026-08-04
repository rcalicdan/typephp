<?php

declare(strict_types=1);

class Test
{
    /**
     * @var int[]
     */
    public array $numbers;

    public function run()
    {
        $this->numbers = [1, 2, 3, 4, 5, 'hello'];
    }
}

new Test()->run();
