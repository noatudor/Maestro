<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain\Collections;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @template TValue
 *
 * @implements IteratorAggregate<int, TValue>
 */
abstract class AbstractCollection implements Countable, IteratorAggregate
{
    /**
     * @param list<TValue> $items
     */
    protected function __construct(
        protected array $items = [],
    ) {}

    /**
     * @return Traversable<int, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * @return list<TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return list<TValue>
     */
    public function values(): array
    {
        return $this->items;
    }

    /**
     * @param (callable(TValue): bool)|null $callback
     *
     * @return TValue|null
     */
    public function first(?callable $callback = null): mixed
    {
        if ($callback === null) {
            return $this->items[0] ?? null;
        }

        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param (callable(TValue): bool)|null $callback
     *
     * @return TValue|null
     */
    public function last(?callable $callback = null): mixed
    {
        if ($callback === null) {
            $count = count($this->items);

            return $count > 0 ? $this->items[$count - 1] : null;
        }

        $result = null;
        foreach ($this->items as $item) {
            if ($callback($item)) {
                $result = $item;
            }
        }

        return $result;
    }

    /**
     * @param callable(TValue): bool $callback
     *
     * @return list<TValue>
     */
    protected function filterItems(callable $callback): array
    {
        return array_values(array_filter($this->items, $callback));
    }

    /**
     * @template TMapValue
     *
     * @param callable(TValue): TMapValue $callback
     *
     * @return list<TMapValue>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    /**
     * @param callable(TValue): bool $callback
     */
    public function any(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(TValue): bool $callback
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if (! $callback($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(TValue): bool $callback
     */
    public function none(callable $callback): bool
    {
        return ! $this->any($callback);
    }

    /**
     * @param callable(TValue): (int|float) $callback
     */
    public function sum(callable $callback): int|float
    {
        $sum = 0;
        foreach ($this->items as $item) {
            $sum += $callback($item);
        }

        return $sum;
    }
}
