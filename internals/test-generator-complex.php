<?php

declare(strict_types=1);

// Standalone Domain Classes
class Animal {}
class Dog extends Animal {}
class Car {}

/**
 * @template T
 */
class Producer
{
    /** @param T $item */
    public function __construct(public mixed $item) {}
}

/**
 * Generator yielding Array Shapes and accepting Array Shapes in $gen->send()
 *
 * @return Generator<int, array{id: positive-int, name: non-empty-string}, array{action: 'approve'|'reject'}, void>
 */
function testShapeGenerator(): Generator
{
    $input = yield 1 => ['id' => 10, 'name' => 'Alice'];
    yield 2 => ['id' => 20, 'name' => "action_{$input['action']}"];
}

/**
 * Generator yielding Generic Objects
 *
 * @return Generator<int, Producer<Dog>>
 */
function testGenericGenerator(): Generator
{
    yield 1 => new Producer(new Dog());
    yield 2 => new Producer(new Car()); // Invalid: Car is not a Dog!
}

echo "=== Testing Complex Generator Type Enforcement ===\n\n";

// 1. Yielding Array Shapes
echo "1. Testing Generator Yielding Array Shapes:\n";
$gen1 = testShapeGenerator();
$firstItem = $gen1->current();
echo "   ✅ Success: Yielded valid shape: " . json_encode($firstItem) . "\n\n";

// 2. Sending Valid Shape into Generator (TSend)
echo "2. Testing \$gen->send() with Valid TSend Shape ('action' => 'approve'):\n";
$secondItem = $gen1->send(['action' => 'approve']);
echo "   ✅ Success: Yielded second shape: " . json_encode($secondItem) . "\n\n";

// 3. Sending Invalid Shape into Generator (TSend)
echo "3. Testing \$gen->send() with Invalid TSend Shape ('action' => 'delete'):\n";
$gen2 = testShapeGenerator();
$gen2->current();

try {
    $gen2->send(['action' => 'delete']);
    echo "   ❌ FAIL: Generator accepted invalid TSend action 'delete'!\n";
} catch (TypeError $e) {
    echo "   ✅ SUCCESS: Caught expected TypeError on TSend!\n";
    echo "      Message: " . $e->getMessage() . "\n\n";
}

// 4. Yielding Invalid Generic Object
echo "4. Testing Generator Yielding Invalid Generic Object (Producer<Car>):\n";
$gen3 = testGenericGenerator();

try {
    foreach ($gen3 as $key => $producer) {
        echo "   Yielded item #{$key}: " . get_class($producer->item) . "\n";
    }
    echo "   ❌ FAIL: Generator yielded Producer<Car> without throwing TypeError!\n";
} catch (TypeError $e) {
    echo "   ✅ SUCCESS: Caught expected TypeError on yield!\n";
    echo "      Message: " . $e->getMessage() . "\n";
}