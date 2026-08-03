<?php

declare(strict_types=1);

class Animal
{
}
class Dog extends Animal
{
}

/**
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
 * @param Producer<Animal> $producer
 */
function handleProducer(Producer $producer)
{
    return $producer->item;
}

// 1. Valid: Producer<Dog> matches Producer<Animal> because class is @template-covariant T
$dogProducer = new Producer(new Dog());
handleProducer($dogProducer); // Passes!

// 2. Fails! string does not extend Animal!
$stringProducer = new Producer('not an animal');
handleProducer($stringProducer); // FAILS HERE!
