<?php

declare(strict_types=1);

namespace TypePHP\Exception;

/**
 * Custom TypeError thrown when a PHPDoc type contract (parameter, return, or variable) is violated.
 * Extends PHP's native \TypeError for full polymorphic compatibility.
 */
class TypeError extends \TypeError
{
}