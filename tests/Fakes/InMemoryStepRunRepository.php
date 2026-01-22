<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * @internal For testing purposes - can be extended in tests
 */
class InMemoryStepRunRepository implements StepRunRepository
{
    /** @var array<string, StepRun> */
    private array $stepRuns = [];

    public function find(StepRunId $stepRunId): ?StepRun
    {
        return $this->stepRuns[$stepRunId->value] ?? null;
    }

    /**
     * @throws StepNotFoundException
     */
    public function findOrFail(StepRunId $stepRunId): StepRun
    {
        $stepRun = $this->find($stepRunId);

        if (! $stepRun instanceof StepRun) {
            throw StepNotFoundException::withId($stepRunId);
        }

        return $stepRun;
    }

    public function save(StepRun $stepRun): void
    {
        $this->stepRuns[$stepRun->id->value] = $stepRun;
    }

    public function findByWorkflowId(WorkflowId $workflowId): StepRunCollection
    {
        $stepRuns = array_filter(
            $this->stepRuns,
            static fn (StepRun $stepRun): bool => $stepRun->workflowId->value === $workflowId->value,
        );

        return new StepRunCollection(array_values($stepRuns));
    }

    public function findByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun
    {
        foreach ($this->stepRuns as $stepRun) {
            if ($stepRun->workflowId->value === $workflowId->value
                && $stepRun->stepKey->value === $stepKey->value) {
                return $stepRun;
            }
        }

        return null;
    }

    public function findLatestByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun
    {
        $matching = array_filter(
            $this->stepRuns,
            static fn (StepRun $stepRun): bool => $stepRun->workflowId->value === $workflowId->value
                && $stepRun->stepKey->value === $stepKey->value,
        );

        if ($matching === []) {
            return null;
        }

        usort($matching, static fn (StepRun $a, StepRun $b): int => $b->attempt <=> $a->attempt);

        return $matching[0];
    }

    public function findByWorkflowIdAndState(WorkflowId $workflowId, StepState $stepState): StepRunCollection
    {
        $stepRuns = array_filter(
            $this->stepRuns,
            static fn (StepRun $stepRun): bool => $stepRun->workflowId->value === $workflowId->value
                && $stepRun->status() === $stepState,
        );

        return new StepRunCollection(array_values($stepRuns));
    }

    public function updateStatusAtomically(StepRunId $stepRunId, StepState $fromState, StepState $toState): bool
    {
        $stepRun = $this->find($stepRunId);

        if (! $stepRun instanceof StepRun || $stepRun->status() !== $fromState) {
            return false;
        }

        if ($toState === StepState::Succeeded) {
            $stepRun->succeed();
        } elseif ($toState === StepState::Failed) {
            $stepRun->fail('ATOMIC_UPDATE', 'Status updated atomically');
        } elseif ($toState === StepState::Running) {
            $stepRun->start();
        }

        $this->save($stepRun);

        return true;
    }

    public function finalizeAsSucceeded(StepRunId $stepRunId, CarbonImmutable $finishedAt): bool
    {
        $stepRun = $this->find($stepRunId);

        if (! $stepRun instanceof StepRun || $stepRun->status() !== StepState::Running) {
            return false;
        }

        $stepRun->succeed();
        $this->save($stepRun);

        return true;
    }

    public function finalizeAsFailed(
        StepRunId $stepRunId,
        string $failureCode,
        string $failureMessage,
        int $failedJobCount,
        CarbonImmutable $finishedAt,
    ): bool {
        $stepRun = $this->find($stepRunId);

        if (! $stepRun instanceof StepRun || $stepRun->status() !== StepState::Running) {
            return false;
        }

        $stepRun->fail($failureCode, $failureMessage);
        $this->save($stepRun);

        return true;
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        $this->stepRuns = array_filter(
            $this->stepRuns,
            static fn (StepRun $stepRun): bool => $stepRun->workflowId->value !== $workflowId->value,
        );
    }

    public function markAsSuperseded(StepRunId $stepRunId, StepRunId $supersededById): bool
    {
        $stepRun = $this->find($stepRunId);

        if (! $stepRun instanceof StepRun || $stepRun->isSuperseded()) {
            return false;
        }

        $stepRun->supersede($supersededById);
        $this->save($stepRun);

        return true;
    }

    /**
     * @param list<StepKey> $stepKeys
     */
    public function findActiveByStepKeys(WorkflowId $workflowId, array $stepKeys): StepRunCollection
    {
        if ($stepKeys === []) {
            return new StepRunCollection([]);
        }

        $stepKeyValues = array_map(
            static fn (StepKey $stepKey): string => $stepKey->value,
            $stepKeys,
        );

        $stepRuns = array_filter(
            $this->stepRuns,
            static fn (StepRun $stepRun): bool => $stepRun->workflowId->value === $workflowId->value
                && in_array($stepRun->stepKey->value, $stepKeyValues, true)
                && ! $stepRun->isSuperseded(),
        );

        return new StepRunCollection(array_values($stepRuns));
    }

    /**
     * @param list<StepKey> $stepKeys
     *
     * @return array<string, StepRun>
     */
    public function findLatestActiveByStepKeys(WorkflowId $workflowId, array $stepKeys): array
    {
        if ($stepKeys === []) {
            return [];
        }

        $result = [];
        foreach ($stepKeys as $stepKey) {
            $matching = array_filter(
                $this->stepRuns,
                static fn (StepRun $stepRun): bool => $stepRun->workflowId->value === $workflowId->value
                    && $stepRun->stepKey->value === $stepKey->value
                    && ! $stepRun->isSuperseded(),
            );

            if ($matching === []) {
                continue;
            }

            usort($matching, static fn (StepRun $a, StepRun $b): int => $b->attempt <=> $a->attempt);
            $result[$stepKey->value] = $matching[0];
        }

        return $result;
    }

    /**
     * @return list<StepRun>
     */
    public function all(): array
    {
        return array_values($this->stepRuns);
    }

    public function count(): int
    {
        return count($this->stepRuns);
    }

    public function clear(): void
    {
        $this->stepRuns = [];
    }
}
