<?php

/**
 * @typephp-ignore-file
 */

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\IgnoreTags;

class IgnoredFile
{
    /**
     * Type-checking is skipped for ALL methods in this file because of @typephp-ignore-file above.
     *
     * @param positive-int $id
     *
     * @return positive-int
     */
    public function process(int $id): int
    {
        return $id;
    }
}
