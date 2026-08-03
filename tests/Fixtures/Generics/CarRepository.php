<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Car;

/**
 * @extends Repository<Car>
 */
class CarRepository extends Repository
{
    public function __construct()
    {
        parent::__construct(null);
    }
}