<?php

declare(strict_types=1);

class Animal
{
}

class Dog extends Animal
{
}

class Car
{
}

/**
 * Generic producer with covariant T
 *
 * @template-covariant T
 */
class Producer
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

/**
 * Generic repository
 *
 * @template T
 */
class Repository
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

/**
 * 1. Nested Generic in @extends
 *
 * @extends Repository<Producer<Dog>>
 */
class ProducerDogRepo extends Repository
{
    public function __construct()
    {
        parent::__construct(new Producer(new Dog()));
    }
}

/**
 * 2. Invalid Nested Generic in @extends (Car is not an Animal)
 *
 * @extends Repository<Producer<Car>>
 */
class ProducerCarRepo extends Repository
{
    public function __construct()
    {
        parent::__construct(new Producer(new Car()));
    }
}

/**
 * 3. Multi-level Generic Inheritance
 *
 * @template T
 *
 * @extends Repository<T>
 */
abstract class BaseRepository extends Repository
{
}

/**
 * @extends BaseRepository<Dog>
 */
class MultiLevelDogRepo extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Dog());
    }
}

/**
 * Accepts Repository<covariant Producer<covariant Animal>>
 *
 * @param Repository<covariant Producer<covariant Animal>> $repo
 */
function handleNestedRepo(Repository $repo): mixed
{
    return $repo->item;
}

/**
 * Accepts Repository<covariant Animal>
 *
 * @param Repository<covariant Animal> $repo
 */
function handleMultiLevelRepo(Repository $repo): mixed
{
    return $repo->item;
}

echo "=== Testing Nested Generics & Multi-Level @extends ===\n\n";

// 1. Nested Generic in @extends
echo "1. Testing @extends Repository<Producer<Dog>> (Valid)...\n";
try {
    handleNestedRepo(new ProducerDogRepo());
    echo "   ✅ ProducerDogRepo passed for Repository<Producer<Animal>>!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n2. Testing @extends Repository<Producer<Car>> (Invalid - Car is not an Animal)...\n";
try {
    handleNestedRepo(new ProducerCarRepo());
    echo "   ❌ Failed to catch invalid nested generic!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// 2. Multi-Level Generic Inheritance
echo "\n3. Testing Multi-Level @extends Inheritance (Dog -> BaseRepo<T> -> Repository<T>)...\n";
try {
    handleMultiLevelRepo(new MultiLevelDogRepo());
    echo "   ✅ MultiLevelDogRepo passed for Repository<Animal>!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 TEST RUN COMPLETED!\n";