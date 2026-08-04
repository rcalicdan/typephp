<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 *
 * @extends Repository<T>
 */
abstract class BaseRepository extends Repository
{
}
