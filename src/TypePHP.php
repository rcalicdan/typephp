<?php

declare(strict_types=1);

namespace TypePHP;

use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;

final class TypePHP
{
    public static function boot(): void
    {
        StreamWrapper::register(Config::get());
    }
}
