<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

trait TraitWithProperties
{
    /**
     * Instance property declared in Trait
     *
     * @var positive-int
     */
    public int $traitInstanceProp = 10;

    /**
     * Static property declared in Trait
     *
     * @var non-empty-string
     */
    public static string $traitStaticProp = 'v1.0';

    public function setTraitInstanceProp(int $val): void
    {
        $this->traitInstanceProp = $val;
    }

    public static function setTraitStaticProp(string $val): void
    {
        self::$traitStaticProp = $val;
    }
}
