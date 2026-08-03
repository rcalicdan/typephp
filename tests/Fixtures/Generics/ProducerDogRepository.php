<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @extends Repository<Producer<Dog>>
 */
class ProducerDogRepository extends Repository
{
    public function __construct()
    {
        parent::__construct(new Producer(new Dog()));
    }
}