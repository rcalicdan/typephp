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

/**
 * @template T
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
 * @param Producer<covariant Animal> $producer
 */
function handleProducer(Producer $producer)
{
    return $producer->item;
}

// 1. Valid: Producer<Dog> satisfies Producer<covariant Animal> because Dog extends Animal!
$dogProducer = new Producer(new Dog());
handleProducer($dogProducer);

// 2. Fails: Producer<string> does NOT satisfy Producer<covariant Animal>
$stringProducer = new Producer('not an animal');
handleProducer($stringProducer);
