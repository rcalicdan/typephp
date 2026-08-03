<?php

declare(strict_types=1);

namespace TypePHP;

final class TypePHP
{
    /**
     * Boots the TypePHP runtime type engine.
     */
    public static function boot(): void
    {
        StreamWrapper::register(Config::get());
    }
}