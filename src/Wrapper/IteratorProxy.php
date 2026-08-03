<?php

declare(strict_types=1);

namespace TypePHP\Wrapper;

use Countable;
use Iterator;
use IteratorAggregate;
use OuterIterator;
use Traversable;

final class IteratorProxy implements OuterIterator, Countable
{
    private Iterator $inner;

    /**
     * @param \Closure(mixed, mixed): void $typeCheckCallback
     */
    public function __construct(
        Traversable $iterable,
        private \Closure $typeCheckCallback
    ) {
        if ($iterable instanceof Iterator) {
            $this->inner = $iterable;
        } elseif ($iterable instanceof IteratorAggregate) {
            $this->inner = $iterable->getIterator();
        } else {
            $this->inner = new \ArrayIterator(iterator_to_array($iterable));
        }
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function valid(): bool
    {
        return $this->inner->valid();
    }

    public function current(): mixed
    {
        $key = $this->inner->key();
        $val = $this->inner->current();
        ($this->typeCheckCallback)($key, $val);

        return $val;
    }

    public function key(): mixed
    {
        return $this->inner->key();
    }

    public function next(): void
    {
        $this->inner->next();
    }

    public function getInnerIterator(): Iterator
    {
        return $this->inner;
    }

    public function count(): int
    {
        if ($this->inner instanceof Countable) {
            return $this->inner->count();
        }

        return iterator_count($this->inner);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->$method(...$args);
    }
}
