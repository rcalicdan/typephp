<?php

declare(strict_types=1);

namespace TypePHP;

if (class_exists(TypePHP::class) && ! \defined('TYPEPHP_BOOTED')) {
    define('TYPEPHP_BOOTED', true);

    $isDisabledEnv = getenv('TYPEPHP_DISABLE') !== false && filter_var(getenv('TYPEPHP_DISABLE'), FILTER_VALIDATE_BOOLEAN);
    $isDisabledConst = \defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE;

    if (! $isDisabledEnv && ! $isDisabledConst) {
        TypePHP::boot();
    }
}