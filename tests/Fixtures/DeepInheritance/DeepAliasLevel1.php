<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\DeepInheritance;

/**
 * @phpstan-type DeepShape array{id: positive-int, score: int<1, 100>}
 */
class DeepAliasLevel1
{
}
