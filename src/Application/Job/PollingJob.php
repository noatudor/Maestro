<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maestro\Workflow\Application\Context\WorkflowContextProvider;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\DispatchableWorkflowJob;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Abstract base class for polling jobs.
 *
 * Polling jobs execute repeatedly until a condition is met.
 * They must return a PollResult indicating whether polling is complete.
 */
abstract class PollingJob implements DispatchableWorkflowJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected ?WorkflowContextProvider $contextProvider = null;

    protected ?StepOutputStore $outputStore = null;

    /**
     * Previous poll attempts, ordered from oldest to newest.
     *
     * @var list<PollAttempt>
     */
    protected array $previousAttempts = [];

    /**
     * Current poll attempt number (1-indexed).
     */
    protected int $currentAttemptNumber = 1;

    public function __construct(
        public readonly WorkflowId $workflowId,
        public readonly StepRunId $stepRunId,
        public readonly string $jobUuid,
    ) {}

    /**
     * Laravel's job handler - do not override.
     *
     * Returns the PollResult which is captured by middleware.
     */
    final public function handle(
        WorkflowContextProvider $workflowContextProvider,
        StepOutputStore $stepOutputStore,
    ): PollResult {
        $this->contextProvider = $workflowContextProvider;
        $this->outputStore = $stepOutputStore;

        return $this->poll();
    }

    /**
     * Set the workflow context provider.
     *
     * @internal
     */
    final public function setContextProvider(WorkflowContextProvider $workflowContextProvider): void
    {
        $this->contextProvider = $workflowContextProvider;
    }

    /**
     * Set the step output store.
     *
     * @internal
     */
    final public function setOutputStore(StepOutputStore $stepOutputStore): void
    {
        $this->outputStore = $stepOutputStore;
    }

    /**
     * Set the previous poll attempts for this job.
     *
     * @internal
     *
     * @param list<PollAttempt> $attempts
     */
    final public function setPreviousAttempts(array $attempts): void
    {
        $this->previousAttempts = $attempts;
    }

    /**
     * Set the current attempt number.
     *
     * @internal
     */
    final public function setCurrentAttemptNumber(int $attemptNumber): void
    {
        $this->currentAttemptNumber = $attemptNumber;
    }

    /**
     * Get the workflow ID this job belongs to.
     */
    final public function getWorkflowId(): WorkflowId
    {
        return $this->workflowId;
    }

    /**
     * Get the step run ID this job is part of.
     */
    final public function getStepRunId(): StepRunId
    {
        return $this->stepRunId;
    }

    /**
     * Get the unique job UUID.
     */
    final public function getJobUuid(): string
    {
        return $this->jobUuid;
    }

    /**
     * Get the correlation metadata for this job.
     *
     * @return array{workflow_id: string, step_run_id: string, job_uuid: string}
     */
    final public function correlationMetadata(): array
    {
        return [
            'workflow_id' => $this->workflowId->value,
            'step_run_id' => $this->stepRunId->value,
            'job_uuid' => $this->jobUuid,
        ];
    }

    /**
     * Execute the poll and return the result.
     *
     * Implement this method with your polling logic.
     * Return CompletedPollResult, ContinuePollResult, or AbortedPollResult.
     */
    abstract protected function poll(): PollResult;

    /**
     * Get the workflow context.
     */
    protected function context(): ?WorkflowContext
    {
        if (! $this->contextProvider instanceof WorkflowContextProvider) {
            return null;
        }

        return $this->contextProvider->get();
    }

    /**
     * Get the workflow context with a specific type.
     *
     * @template T of WorkflowContext
     *
     * @param class-string<T> $contextClass
     *
     * @return T|null
     */
    protected function contextAs(string $contextClass): ?WorkflowContext
    {
        if (! $this->contextProvider instanceof WorkflowContextProvider) {
            return null;
        }

        return $this->contextProvider->getTyped($contextClass);
    }

    /**
     * Get the step output store for reading and writing outputs.
     */
    protected function outputs(): ?StepOutputStore
    {
        return $this->outputStore;
    }

    /**
     * Get all previous poll attempts.
     *
     * @return list<PollAttempt>
     */
    protected function previousAttempts(): array
    {
        return $this->previousAttempts;
    }

    /**
     * Get the last poll attempt, if any.
     */
    protected function lastAttempt(): ?PollAttempt
    {
        if ($this->previousAttempts === []) {
            return null;
        }

        return $this->previousAttempts[count($this->previousAttempts) - 1];
    }

    /**
     * Get the current poll attempt number (1-indexed).
     */
    protected function attemptNumber(): int
    {
        return $this->currentAttemptNumber;
    }

    /**
     * Check if this is the first poll attempt.
     */
    protected function isFirstAttempt(): bool
    {
        return $this->currentAttemptNumber === 1;
    }
}
