<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Fixture class testing PHP 8.4 Property Hooks (get and set) with @var docblocks.
 */
class PropertyHooks
{
    /**
     * @var int[]
     */
    public array $shortGetNumbers {
        get => ['hello', 1];
    }

    /**
     * @var int[]
     */
    public array $blockGetNumbers {
        get {
            return [1, 2, 'invalid'];
        }
    }

    /**
     * @var positive-int
     */
    public int $shortSetNumber {
        set => $this->_shortSetNumber = $value;
    }

    public int $_shortSetNumber = 10;

    /**
     * @var positive-int
     */
    public int $blockSetNumber {
        set(int $val) {
            $this->_blockSetNumber = $val;
        }
    }

    public int $_blockSetNumber = 10;

    /**
     * Ignored property hook - Hook validation skipped
     *
     * @typephp-ignore
     *
     * @var positive-int
     */
    public int $unvalidatedHook {
        get => $this->_unvalidatedVal;
        set => $this->_unvalidatedVal = $value;
    }

    public int $_unvalidatedVal = 10;
}
