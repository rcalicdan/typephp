<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

class TraitImplementation implements TraitInterfaceContract
{
    use ExecutorTrait; // Trait method fulfills interface contract
}
