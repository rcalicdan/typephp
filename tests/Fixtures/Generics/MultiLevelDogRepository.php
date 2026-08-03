<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @extends BaseRepository<Dog>
 */
class MultiLevelDogRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Dog());
    }
}