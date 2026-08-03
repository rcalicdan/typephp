<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class UserService extends BaseService
{
    public function find(int $id): array
    {
        if ($id === 999) {
            return ['id' => -5, 'name' => 'Invalid'];
        }

        return parent::find($id);
    }
}