<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maestro\Workflow\Application\Context\WorkflowContextProvider;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Abstract base class for all workflow-orchestrated jobs.
 *
 * All jobs participating in workflow orchestration must extend this class.
 * It provides access to workflow context, step outputs, and carries
 * correlation metadata for lifecycle tracking.
 */
abstract class OrchestratedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The workflow instance this job belongs to.
     */
    public readonly WorkflowId $workflowId;

    /**
     * The step run this job is part of.
     */
    public readonly StepRunId $stepRunId;

    /**
     * Unique identifier for this specific job dispatch.
     * Used for correlation in job ledger and idempotency checks.
     */
    public readonly string $jobUuid;

    /**
     * Workflow context provider (injected during job execution).
     */
    protected ?WorkflowContextProvider $contextProvider = null;

    /**
     * Step output store (injected during job execution).
     */
    protected ?StepOutputStore $outputStore = null;

    public function __construct(
        WorkflowId $workflowId,
        StepRunId $stepRunId,
        string $jobUuid,
    ) {
        $this->workflowId = $workflowId;
        $this->stepRunId = $stepRunId;
        $this->jobUuid = $jobUuid;
    }

    /**
     * Laravel's job handler - do not override.
     *
     * This method sets up the execution context and delegates to execute().
     * Lifecycle tracking is handled by middleware.
     */
    final public function handle(
        WorkflowContextProvider $contextProvider,
        StepOutputStore $outputStore,
    ): void {
        $this->contextProvider = $contextProvider;
        $this->outputStore = $outputStore;

        $this->execute();
    }

    /**
     * Set the workflow context provider.
     *
     * This is typically called by the job lifecycle middleware.
     *
     * @internal
     */
    final public function setContextProvider(WorkflowContextProvider $provider): void
    {
        $this->contextProvider = $provider;
    }

    /**
     * Set the step output store.
     *
     * This is typically called by the job lifecycle middleware.
     *
     * @internal
     */
    final public function setOutputStore(StepOutputStore $store): void
    {
        $this->outputStore = $store;
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
     * Execute the job logic.
     *
     * Implement this method with your job's business logic.
     * Use context() to access workflow context and outputs() to read/write step outputs.
     */
    abstract protected function execute(): void;

    /**
     * Get the workflow context.
     *
     * Returns the typed workflow context loaded by the ContextLoader
     * configured in the workflow definition.
     */
    protected function context(): ?WorkflowContext
    {
        if ($this->contextProvider === null) {
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
        if ($this->contextProvider === null) {
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
}
