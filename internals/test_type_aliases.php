<?php

declare(strict_types=1);

/**
 * Shared source class defining global type aliases
 *
 * @phpstan-type UserShape array{id: positive-int, name: non-empty-string}
 * @phpstan-type RoleType 'admin'|'editor'|'viewer'
 */
class GlobalTypes
{
}

/**
 * Service importing types from GlobalTypes and defining local alias
 *
 * @phpstan-import-type UserShape from GlobalTypes
 * @phpstan-import-type RoleType from GlobalTypes as UserRole
 *
 * @phpstan-type LocalStatus 'active'|'inactive'
 */
class UserService
{
    /**
     * 1. LocalStatus ('active'|'inactive')
     * 2. UserShape (array{id: positive-int, name: non-empty-string})
     *
     * @param UserShape $user
     * @param LocalStatus $status
     */
    public function updateUser(array $user, string $status): bool
    {
        return true;
    }

    /**
     * 3. RoleType aliased AS UserRole ('admin'|'editor'|'viewer')
     *
     * @param UserRole $role
     */
    public function setRole(string $role): bool
    {
        return true;
    }
}

echo "=== Testing @phpstan-type and @phpstan-import-type ===\n\n";

$service = new UserService();

// -------------------------------------------------------------
// 1. Local Type Aliases (@phpstan-type LocalStatus)
// -------------------------------------------------------------
echo "1. Testing Local Type Alias (@phpstan-type LocalStatus)...\n";

try {
    $service->updateUser(['id' => 1, 'name' => 'Alice'], 'active');
    echo "   ✅ Valid LocalStatus passed!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $service->updateUser(['id' => 1, 'name' => 'Alice'], 'invalid_status');
    echo "   ❌ Failed to catch invalid LocalStatus!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 2. Imported Type Aliases (@phpstan-import-type UserShape from GlobalTypes)
// -------------------------------------------------------------
echo "\n2. Testing Imported Type Alias (@phpstan-import-type UserShape from GlobalTypes)...\n";

try {
    // Invalid UserShape: id is negative (-1)
    $service->updateUser(['id' => -1, 'name' => 'Alice'], 'active');
    echo "   ❌ Failed to catch invalid imported UserShape!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 3. Imported Type Aliases WITH 'as' Alias
// -------------------------------------------------------------
echo "\n3. Testing Imported Type Alias with 'as' (@phpstan-import-type RoleType ... as UserRole)...\n";

try {
    $service->setRole('admin');
    echo "   ✅ Valid UserRole passed!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

try {
    $service->setRole('superadmin');
    echo "   ❌ Failed to catch invalid UserRole!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 BASELINE TEST COMPLETED!\n";
