<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class FluentService
{
    /**
     * @return $this
     */
    public function setValidSelf(): self
    {
        return $this;
    }

    /**
     * @return $this
     */
    public function setInvalidSelf(): self
    {
        return new self();
    }
}