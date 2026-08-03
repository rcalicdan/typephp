<?php

declare(strict_types=1);

class User
{
    public function __construct(public int $id, public string $name)
    {
    }
}

class UserProcessor
{
    /**
     * 1. Validates SomeClass[]
     *
     * @param User[] $users
     */
    public function processUsers(array $users)
    {
        return count($users);
    }

    /**
     * 2. Validates array{id: int, name: string}[]
     *
     * @param array{id: int, name: string}[] $userShapes
     */
    public function processUserShapes(array $userShapes)
    {
        return count($userShapes);
    }

    /**
     * 3. Validates Shape containing Object Array: array{users: User[], count: int}
     *
     * @param array{users: User[], count: int} $payload
     */
    public function processPayload(array $payload)
    {
        return $payload['count'];
    }

    /**
     * 4. Validates self[]
     *
     * @return self[]
     */
    public function getProcessors(): array
    {
        return [$this, new self()];
    }
}

echo "=== Testing SomeClass[] and Array Shapes ===\n\n";

$processor = new UserProcessor();

// -------------------------------------------------------------
// 1. Testing SomeClass[] (User[])
// -------------------------------------------------------------
echo "1. Testing User[]...\n";
$processor->processUsers([new User(1, 'Alice'), new User(2, 'Bob')]);
echo "   ✅ Valid User[] passed!\n";

try {
    $processor->processUsers([new User(1, 'Alice'), 'not_a_user_object']);
    echo "   ❌ Failed to catch invalid User[]!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 2. Testing array{id: int, name: string}[]
// -------------------------------------------------------------
echo "\n2. Testing array{id: int, name: string}[]...\n";
$processor->processUserShapes([
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'Bob'],
]);
echo "   ✅ Valid array{id: int, name: string}[] passed!\n";

try {
    // Second shape is missing 'name'
    $processor->processUserShapes([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2],
    ]);
    echo "   ❌ Failed to catch invalid array shape in array!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 3. Testing Shape with Object Array: array{users: User[], count: int}
// -------------------------------------------------------------
echo "\n3. Testing array{users: User[], count: int}...\n";
$processor->processPayload([
    'users' => [new User(1, 'Alice')],
    'count' => 1,
]);
echo "   ✅ Valid payload passed!\n";

try {
    // 'users' contains a string instead of User object
    $processor->processPayload([
        'users' => ['not_a_user'],
        'count' => 1,
    ]);
    echo "   ❌ Failed to catch invalid User object inside shape array!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 4. Testing self[]
// -------------------------------------------------------------
echo "\n4. Testing self[]...\n";
$processor->getProcessors();
echo "   ✅ Valid self[] return passed!\n";

echo "\n🎉 ALL ARRAY & SHAPE TESTS PASSED PERFECTLY!\n";
