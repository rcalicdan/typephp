<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\IgnoreTags;

class IgnoredMethod
{
    /**
     * Normal property -> Type-checking active.
     *
     * @var positive-int
     */
    public int $normalProperty = 10;

    /**
     * Ignored property -> Property assignment checks skipped!
     *
     * @typephp-ignore
     *
     * @var positive-int
     */
    public int $ignoredProperty = 10;

    public function setNormalProperty(int $val): void
    {
        $this->normalProperty = $val;
    }

    public function setIgnoredProperty(int $val): void
    {
        $this->ignoredProperty = $val;
    }

    /**
     * Normal method - Type-checking active.
     *
     * @param positive-int $id
     */
    public function normalMethod(int $id): bool
    {
        return true;
    }

    /**
     * Ignored method - Type-checking skipped for this method only.
     *
     * @typephp-ignore
     *
     * @param positive-int $id
     *
     * @return positive-int
     */
    public function ignoredMethod(int $id): int
    {
        return $id;
    }
}
