<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * @phpstan-import-type SharedShape from GlobalTypes as LocalUserShape
 */
class UserApi
{
    /**
     * @param LocalUserShape $user
     */
    public function saveUser(array $user): bool
    {
        return true;
    }
}