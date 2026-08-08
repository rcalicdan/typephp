<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Oop;

class ChildClassInheritingTraitParent extends ParentClassWithTrait
{
    // Inherits logMessage() from ParentClassWithTrait without docblock
}