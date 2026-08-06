<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\IgnoreTags;

class ForceCheckedMethod
{
    /**
     * @typephp-ignore
     * @param positive-int $id
     * @return positive-int
     */
    public function ignoredMethod(int $id): int
    {
        return $id;
    }
}