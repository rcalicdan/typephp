<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class HookedInterfaceImplementation implements HookedInterfaceProperty
{
    public int $readOnlyProp {
        get => $this->_readOnlyVal;
    }

    public int $_readOnlyVal = 10;

    public string $readWriteProp {
        get => $this->_readWriteVal;
        set => $this->_readWriteVal = trim($value);
    }

    public string $_readWriteVal = 'Alice';

    public int $writeOnlyProp {
        set => $this->_writeOnlyVal = $value;
    }

    public int $_writeOnlyVal = 100;
}
