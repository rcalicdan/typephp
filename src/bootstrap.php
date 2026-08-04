<?php

declare(strict_types=1);

namespace TypePHP;

if (class_exists(TypePHP::class) && ! \defined('TYPEPHP_BOOTED')) {
    define('TYPEPHP_BOOTED', true);
    TypePHP::boot();
}