<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class BaseService
{
    /**
     * @param positive-int $id
     *
     * @return array{id: positive-int, name: string}
     */
    public function find(int $id): array
    {
        return ['id' => $id, 'name' => 'Alice'];
    }
}
