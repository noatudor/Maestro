<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\ValueObjects\StepKey;
use Traversable;

/**
 * An ordered collection of step definitions.
 *
 * @implements IteratorAggregate<int, StepDefinition>
 */
final readonly class StepCollection implements Countable, IteratorAggregate
{
    /** @var list<StepDefinition> */
    private array $steps;

    /** @var array<string, int> */
    private array $keyIndex;

    /**
     * @param list<StepDefinition> $steps
     */
    private function __construct(array $steps)
    {
        $this->steps = $steps;
        $this->keyIndex = $this->buildKeyIndex($steps);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<StepDefinition> $steps
     */
    public static function fromArray(array $steps): self
    {
        return new self($steps);
    }

    public function add(StepDefinition $step): self
    {
        return new self([...$this->steps, $step]);
    }

    /**
     * @return Traversable<int, StepDefinition>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->steps);
    }

    public function count(): int
    {
        return count($this->steps);
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
     * @return list<StepDefinition>
     */
    public function all(): array
    {
        return $this->steps;
    }

    public function first(): ?StepDefinition
    {
        return $this->steps[0] ?? null;
    }

    public function last(): ?StepDefinition
    {
        $count = count($this->steps);

        return $count > 0 ? $this->steps[$count - 1] : null;
    }

    public function get(int $index): ?StepDefinition
    {
        return $this->steps[$index] ?? null;
    }

    public function findByKey(StepKey $key): ?StepDefinition
    {
        $index = $this->keyIndex[$key->toString()] ?? null;

        return $index !== null ? $this->steps[$index] : null;
    }

    public function hasKey(StepKey $key): bool
    {
        return isset($this->keyIndex[$key->toString()]);
    }

    public function indexOf(StepKey $key): ?int
    {
        return $this->keyIndex[$key->toString()] ?? null;
    }

    public function getNextStep(StepKey $currentKey): ?StepDefinition
    {
        $index = $this->indexOf($currentKey);

        if ($index === null) {
            return null;
        }

        return $this->steps[$index + 1] ?? null;
    }

    public function isLastStep(StepKey $key): bool
    {
        $index = $this->indexOf($key);

        return $index !== null && $index === count($this->steps) - 1;
    }

    public function isFirstStep(StepKey $key): bool
    {
        $index = $this->indexOf($key);

        return $index === 0;
    }

    /**
     * @return list<StepKey>
     */
    public function keys(): array
    {
        return array_map(
            static fn (StepDefinition $step): StepKey => $step->key(),
            $this->steps,
        );
    }

    /**
     * Get all steps that come before the given step.
     */
    public function stepsBefore(StepKey $key): self
    {
        $index = $this->indexOf($key);

        if ($index === null || $index === 0) {
            return self::empty();
        }

        return new self(array_slice($this->steps, 0, $index));
    }

    /**
     * Get all steps that come after the given step.
     */
    public function stepsAfter(StepKey $key): self
    {
        $index = $this->indexOf($key);

        if ($index === null) {
            return self::empty();
        }

        return new self(array_slice($this->steps, $index + 1));
    }

    /**
     * @template T
     *
     * @param callable(StepDefinition): T $callback
     *
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->steps);
    }

    /**
     * @param callable(StepDefinition): bool $callback
     */
    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->steps, $callback)));
    }

    /**
     * @param callable(StepDefinition): bool $callback
     */
    public function any(callable $callback): bool
    {
        foreach ($this->steps as $step) {
            if ($callback($step)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(StepDefinition): bool $callback
     */
    public function every(callable $callback): bool
    {
        foreach ($this->steps as $step) {
            if (! $callback($step)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<StepDefinition> $steps
     *
     * @return array<string, int>
     */
    private function buildKeyIndex(array $steps): array
    {
        $index = [];
        foreach ($steps as $i => $step) {
            $index[$step->key()->toString()] = $i;
        }

        return $index;
    }
}
