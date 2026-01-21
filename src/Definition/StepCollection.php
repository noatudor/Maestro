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
    /** @var array<string, int> */
    private array $keyIndex;

    /**
     * @param list<StepDefinition> $steps
     */
    private function __construct(private array $steps)
    {
        $this->keyIndex = $this->buildKeyIndex($this->steps);
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

    public function add(StepDefinition $stepDefinition): self
    {
        return new self([...$this->steps, $stepDefinition]);
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

    public function findByKey(StepKey $stepKey): ?StepDefinition
    {
        $index = $this->keyIndex[$stepKey->toString()] ?? null;

        return $index !== null ? $this->steps[$index] : null;
    }

    public function hasKey(StepKey $stepKey): bool
    {
        return isset($this->keyIndex[$stepKey->toString()]);
    }

    public function indexOf(StepKey $stepKey): ?int
    {
        return $this->keyIndex[$stepKey->toString()] ?? null;
    }

    public function getNextStep(StepKey $stepKey): ?StepDefinition
    {
        $index = $this->indexOf($stepKey);

        if ($index === null) {
            return null;
        }

        return $this->steps[$index + 1] ?? null;
    }

    public function isLastStep(StepKey $stepKey): bool
    {
        $index = $this->indexOf($stepKey);

        return $index !== null && $index === count($this->steps) - 1;
    }

    public function isFirstStep(StepKey $stepKey): bool
    {
        $index = $this->indexOf($stepKey);

        return $index === 0;
    }

    /**
     * @return list<StepKey>
     */
    public function keys(): array
    {
        return array_map(
            static fn (StepDefinition $stepDefinition): StepKey => $stepDefinition->key(),
            $this->steps,
        );
    }

    /**
     * Get all steps that come before the given step.
     */
    public function stepsBefore(StepKey $stepKey): self
    {
        $index = $this->indexOf($stepKey);

        if ($index === null || $index === 0) {
            return self::empty();
        }

        return new self(array_slice($this->steps, 0, $index));
    }

    /**
     * Get all steps that come after the given step.
     */
    public function stepsAfter(StepKey $stepKey): self
    {
        $index = $this->indexOf($stepKey);

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
        return array_any($this->steps, static fn ($step) => $callback($step));
    }

    /**
     * @param callable(StepDefinition): bool $callback
     */
    public function every(callable $callback): bool
    {
        return array_all($this->steps, static fn ($step) => $callback($step));
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
