<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;

/**
 * Fixture class with both instance and static properties containing @var docblocks.
 */
class ConfiguredProperty
{
    /**
     * @var int[]
     */
    public array $numbers;

    /**
     * @var Dog
     */
    public object $animal;

    /**
     * @var Producer<Dog>
     */
    public object $producer;

    /**
     * @var string
     */
    public static mixed $staticTitle = 'TypePHP';

    public function assignNumbers(array $nums): void
    {
        $this->numbers = $nums;
    }

    public function assignAnimal(object $obj): void
    {
        $this->animal = $obj;
    }

    public function assignProducer(object $obj): void
    {
        $this->producer = $obj;
    }

    public static function assignStaticTitle(mixed $title): void
    {
        self::$staticTitle = $title;
    }
}
