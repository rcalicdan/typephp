<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

interface HookedInterfaceProperty
{
    /**
     * Read-only interface property {get;}
     *
     * @var positive-int
     */
    public int $readOnlyProp { get; }

    /**
     * Read-write interface property {get; set;}
     *
     * @var non-empty-string
     */
    public string $readWriteProp { get; set; }

    /**
     * Write-only interface property {set;}
     *
     * @var positive-int
     */
    public int $writeOnlyProp { set; }
}
