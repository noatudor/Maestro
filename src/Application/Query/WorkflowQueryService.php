<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Query;

use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Http\Responses\WorkflowDetailDTO;
use Maestro\Workflow\Http\Responses\WorkflowListDTO;
use Maestro\Workflow\Http\Responses\WorkflowStatusDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Service for querying workflow data.
 *
 * Provides read-only access to workflows, steps, jobs, and outputs
 * for dashboards, APIs, and operational tooling.
 */
final readonly class WorkflowQueryService
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private StepRunRepository $stepRunRepository,
        private JobLedgerRepository $jobLedgerRepository,
        private StepOutputRepository $stepOutputRepository,
    ) {}

    /**
     * Get workflow status by ID.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowStatus(WorkflowId $workflowId): WorkflowStatusDTO
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        return WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);
    }

    /**
     * Get detailed workflow information including steps and jobs.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowDetail(WorkflowId $workflowId): WorkflowDetailDTO
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);
        $stepRunCollection = $this->stepRunRepository->findByWorkflowId($workflowId);
        $jobRecordCollection = $this->jobLedgerRepository->findByWorkflowId($workflowId);

        return WorkflowDetailDTO::create($workflowInstance, $stepRunCollection, $jobRecordCollection);
    }

    /**
     * Get workflows by state.
     */
    public function getWorkflowsByState(WorkflowState $workflowState): WorkflowListDTO
    {
        $workflows = $this->workflowRepository->findByState($workflowState);

        return WorkflowListDTO::fromWorkflowInstances(array_values($workflows));
    }

    /**
     * Get all running workflows.
     */
    public function getRunningWorkflows(): WorkflowListDTO
    {
        $workflows = $this->workflowRepository->findRunning();

        return WorkflowListDTO::fromWorkflowInstances($workflows);
    }

    /**
     * Get all paused workflows.
     */
    public function getPausedWorkflows(): WorkflowListDTO
    {
        $workflows = $this->workflowRepository->findPaused();

        return WorkflowListDTO::fromWorkflowInstances($workflows);
    }

    /**
     * Get all failed workflows.
     */
    public function getFailedWorkflows(): WorkflowListDTO
    {
        $workflows = $this->workflowRepository->findFailed();

        return WorkflowListDTO::fromWorkflowInstances($workflows);
    }

    /**
     * Get workflows by definition key.
     */
    public function getWorkflowsByDefinition(DefinitionKey $definitionKey): WorkflowListDTO
    {
        $workflows = $this->workflowRepository->findByDefinitionKey($definitionKey->value);

        return WorkflowListDTO::fromWorkflowInstances($workflows);
    }

    /**
     * Get step runs for a workflow.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowSteps(WorkflowId $workflowId): StepRunCollection
    {
        $this->workflowRepository->findOrFail($workflowId);

        return $this->stepRunRepository->findByWorkflowId($workflowId);
    }

    /**
     * Get job records for a workflow.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowJobs(WorkflowId $workflowId): JobRecordCollection
    {
        $this->workflowRepository->findOrFail($workflowId);

        return $this->jobLedgerRepository->findByWorkflowId($workflowId);
    }

    /**
     * Get a step output value.
     *
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     *
     * @throws WorkflowNotFoundException
     */
    public function getStepOutput(WorkflowId $workflowId, string $outputClass): ?StepOutput
    {
        $this->workflowRepository->findOrFail($workflowId);

        return $this->stepOutputRepository->find($workflowId, $outputClass);
    }

    /**
     * Check if a step output exists.
     *
     * @param class-string<StepOutput> $outputClass
     *
     * @throws WorkflowNotFoundException
     */
    public function hasStepOutput(WorkflowId $workflowId, string $outputClass): bool
    {
        $this->workflowRepository->findOrFail($workflowId);

        return $this->stepOutputRepository->has($workflowId, $outputClass);
    }

    /**
     * Check if a workflow exists.
     */
    public function workflowExists(WorkflowId $workflowId): bool
    {
        return $this->workflowRepository->exists($workflowId);
    }

    /**
     * Get the raw workflow instance.
     *
     * @throws WorkflowNotFoundException
     */
    public function findWorkflow(WorkflowId $workflowId): WorkflowInstance
    {
        return $this->workflowRepository->findOrFail($workflowId);
    }
}
