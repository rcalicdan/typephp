<?php

declare(strict_types=1);

class Test
{
    /**
     * @var int[]
     */
    public array $numbers {
        get => ['hello', 1];
    }

    public function run()
    {
        $numbers = $this->numbers;
        var_dump($numbers);
    }
}

new Test()->run();
