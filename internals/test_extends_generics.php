<?php

declare(strict_types=1);

class Animal
{
}

class Dog extends Animal
{
}

class Cat extends Animal
{
}

class Car
{
}

/**
 * Generic parent class
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
 * Generic interface
 *
 * @template T
 */
interface ProcessorInterface
{
    /**
     * @param T $item
     *
     * @return T
     */
    public function process(mixed $item): mixed;
}

/**
 * Fulfills T = Dog via @extends
 *
 * @extends Repository<Dog>
 */
class DogRepository extends Repository
{
    public function __construct()
    {
        // Passes null so constructor doesn't bind T automatically
        parent::__construct(null);
    }
}

/**
 * Fulfills T = Car via @extends
 *
 * @extends Repository<Car>
 */
class CarRepository extends Repository
{
    public function __construct()
    {
        // Passes null so constructor doesn't bind T automatically
        parent::__construct(null);
    }
}

/**
 * Fulfills T = Cat via @implements
 *
 * @implements ProcessorInterface<Cat>
 */
class CatProcessor implements ProcessorInterface
{
    public function process(mixed $item): mixed
    {
        return $item;
    }
}

/**
 * Accepts Repository<covariant Animal>
 *
 * @param Repository<covariant Animal> $repo
 */
function handleAnimalRepo(Repository $repo): mixed
{
    return $repo;
}

/**
 * Accepts ProcessorInterface<covariant Animal>
 *
 * @param ProcessorInterface<covariant Animal> $processor
 */
function handleAnimalProcessor(ProcessorInterface $processor): mixed
{
    return $processor;
}

echo "=== Testing @extends and @implements Generic Annotations ===\n\n";

// -------------------------------------------------------------
// 1. Testing @extends Repository<Dog> vs Repository<Car>
// -------------------------------------------------------------
echo "1. Testing @extends Repository<Dog> (Valid)...\n";
try {
    handleAnimalRepo(new DogRepository());
    echo "   ✅ DogRepository passed for Repository<covariant Animal>!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n2. Testing @extends Repository<Car> (Invalid - Car is not an Animal)...\n";
try {
    handleAnimalRepo(new CarRepository());
    echo "   ❌ Failed to catch invalid @extends Repository<Car> for Repository<covariant Animal>!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// 2. Testing @implements ProcessorInterface<Cat>
// -------------------------------------------------------------
echo "\n3. Testing @implements ProcessorInterface<Cat> (Valid)...\n";
try {
    handleAnimalProcessor(new CatProcessor());
    echo "   ✅ CatProcessor passed for ProcessorInterface<covariant Animal>!\n";
} catch (TypeError $e) {
    echo '   ❌ UNEXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 TEST RUN COMPLETED!\n";