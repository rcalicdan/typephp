<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @extends Repository<Dog>
 */
class DogRepository extends Repository
{
    public function __construct()
    {
        parent::__construct(new Dog());
    }
}
