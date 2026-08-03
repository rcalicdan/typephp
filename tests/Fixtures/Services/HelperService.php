<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class HelperService
{
    public function formatUser(int $id): string
    {
        if ($id <= 0) {
            return ''; // Returns empty string, violating non-empty-string
        }

        return "user_{$id}";
    }

    public static function staticFormat(int $id): string
    {
        return "static_user_{$id}";
    }
}