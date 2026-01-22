<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Contracts\CompensationRunRepository;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Config\CompensationDefinition;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\CompensationRun;
use Maestro\Workflow\Domain\Events\CompensationCompleted;
use Maestro\Workflow\Domain\Events\CompensationFailed;
use Maestro\Workflow\Domain\Events\CompensationStarted;
use Maestro\Workflow\Domain\Events\CompensationStepFailed;
use Maestro\Workflow\Domain\Events\CompensationStepStarted;
use Maestro\Workflow\Domain\Events\CompensationStepSucceeded;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\CompensationRunStatus;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Executes compensation (rollback) for workflows.
 *
 * Compensation jobs are executed sequentially in reverse step order.
 * Each job is dispatched to the queue and this service is called back
 * when the job completes or fails.
 */
final readonly class CompensationExecutor
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private CompensationRunRepository $compensationRunRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private JobDispatchService $jobDispatchService,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Initiate compensation for a workflow.
     *
     * @param list<StepKey>|null $stepKeys Step keys to compensate (null = determined by scope)
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function initiate(
        WorkflowId $workflowId,
        CompensationScope $compensationScope,
        ?array $stepKeys = null,
        ?string $initiatedBy = null,
        ?string $reason = null,
    ): void {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $stepsToCompensate = $this->buildCompensationPlan(
            $workflowInstance,
            $workflowDefinition,
            $compensationScope,
            $stepKeys,
        );

        if ($stepsToCompensate === []) {
            $workflowInstance->startCompensation();
            $workflowInstance->completeCompensation();
            $this->workflowRepository->save($workflowInstance);

            return;
        }

        $this->createCompensationRuns($workflowInstance, $workflowDefinition, $stepsToCompensate);

        $workflowInstance->startCompensation();
        $this->workflowRepository->save($workflowInstance);

        $stepKeyStrings = array_map(
            static fn (StepKey $stepKey): string => $stepKey->value,
            $stepsToCompensate,
        );

        $this->eventDispatcher->dispatch(new CompensationStarted(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            scope: $compensationScope,
            stepKeysToCompensate: $stepKeyStrings,
            totalSteps: count($stepsToCompensate),
            initiatedBy: $initiatedBy,
            reason: $reason,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->dispatchNextCompensation($workflowInstance);
    }

    /**
     * Record a successful compensation job and advance to the next.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function recordSuccess(CompensationRunId $compensationRunId): void
    {
        $compensationRun = $this->compensationRunRepository->find($compensationRunId);

        if (! $compensationRun instanceof CompensationRun || ! $compensationRun->isRunning()) {
            return;
        }

        $startedAt = $compensationRun->startedAt();
        $durationMs = $startedAt instanceof CarbonImmutable
            ? (int) CarbonImmutable::now()->diffInMilliseconds($startedAt)
            : 0;

        $compensationRun->succeed();
        $this->compensationRunRepository->save($compensationRun);

        $workflowInstance = $this->workflowRepository->findOrFail($compensationRun->workflowId);

        $this->eventDispatcher->dispatch(new CompensationStepSucceeded(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            stepKey: $compensationRun->stepKey,
            compensationRunId: $compensationRun->id,
            attempt: $compensationRun->attempt(),
            durationMs: $durationMs,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->advanceCompensation($workflowInstance);
    }

    /**
     * Record a failed compensation job.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function recordFailure(
        CompensationRunId $compensationRunId,
        ?string $failureMessage = null,
        ?string $failureTrace = null,
    ): void {
        $compensationRun = $this->compensationRunRepository->find($compensationRunId);

        if (! $compensationRun instanceof CompensationRun || ! $compensationRun->isRunning()) {
            return;
        }

        $compensationRun->fail($failureMessage, $failureTrace);
        $this->compensationRunRepository->save($compensationRun);

        $workflowInstance = $this->workflowRepository->findOrFail($compensationRun->workflowId);

        $willRetry = $compensationRun->canRetry();

        $this->eventDispatcher->dispatch(new CompensationStepFailed(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            stepKey: $compensationRun->stepKey,
            compensationRunId: $compensationRun->id,
            attempt: $compensationRun->attempt(),
            maxAttempts: $compensationRun->maxAttempts(),
            failureMessage: $failureMessage,
            willRetry: $willRetry,
            occurredAt: CarbonImmutable::now(),
        ));

        if ($willRetry) {
            $compensationRun->resetForRetry();
            $this->compensationRunRepository->save($compensationRun);
            $this->dispatchCompensationJob($workflowInstance, $compensationRun);

            return;
        }

        $this->failCompensation($workflowInstance, $compensationRun);
    }

    /**
     * Skip a failed compensation step and continue.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function skipStep(CompensationRunId $compensationRunId): void
    {
        $compensationRun = $this->compensationRunRepository->find($compensationRunId);

        if (! $compensationRun instanceof CompensationRun) {
            return;
        }

        if (! $compensationRun->isFailed()) {
            return;
        }

        $compensationRun->skip();
        $this->compensationRunRepository->save($compensationRun);

        $workflowInstance = $this->workflowRepository->findOrFail($compensationRun->workflowId);

        if ($workflowInstance->isCompensationFailed()) {
            $workflowInstance->retryCompensation();
            $this->workflowRepository->save($workflowInstance);
        }

        $this->advanceCompensation($workflowInstance);
    }

    /**
     * Skip all remaining compensation steps.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function skipRemaining(WorkflowId $workflowId): void
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if (! $workflowInstance->isCompensationFailed()) {
            return;
        }

        $pendingRuns = $this->compensationRunRepository->findByWorkflowAndStatus(
            $workflowId,
            CompensationRunStatus::Pending,
        );

        $failedRuns = $this->compensationRunRepository->findByWorkflowAndStatus(
            $workflowId,
            CompensationRunStatus::Failed,
        );

        foreach ([...$pendingRuns, ...$failedRuns] as $run) {
            $run->skip();
            $this->compensationRunRepository->save($run);
        }

        $workflowInstance->skipRemainingCompensation();
        $this->workflowRepository->save($workflowInstance);

        $this->dispatchCompletedEvent($workflowInstance);
    }

    /**
     * Retry compensation from a failed state.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function retryCompensation(WorkflowId $workflowId): void
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if (! $workflowInstance->isCompensationFailed()) {
            return;
        }

        $failedRuns = $this->compensationRunRepository->findByWorkflowAndStatus(
            $workflowId,
            CompensationRunStatus::Failed,
        );

        foreach ($failedRuns as $failedRun) {
            $failedRun->resetForRetry();
            $this->compensationRunRepository->save($failedRun);
        }

        $workflowInstance->retryCompensation();
        $this->workflowRepository->save($workflowInstance);

        $this->dispatchNextCompensation($workflowInstance);
    }

    /**
     * Build the list of steps to compensate in reverse order.
     *
     * @param list<StepKey>|null $stepKeys
     *
     * @return list<StepKey>
     */
    private function buildCompensationPlan(
        WorkflowInstance $workflowInstance,
        WorkflowDefinition $workflowDefinition,
        CompensationScope $compensationScope,
        ?array $stepKeys,
    ): array {
        $allSteps = $workflowDefinition->steps()->all();
        $stepsWithCompensation = [];

        foreach ($allSteps as $step) {
            if ($step->hasCompensation()) {
                $stepsWithCompensation[$step->key()->value] = $step;
            }
        }

        if ($stepsWithCompensation === []) {
            return [];
        }

        $keysToCompensate = match (true) {
            $stepKeys !== null => array_map(
                static fn (StepKey $stepKey): string => $stepKey->value,
                $stepKeys,
            ),
            $compensationScope->compensatesAll() => array_keys($stepsWithCompensation),
            $compensationScope->compensatesFailedStepOnly() => $this->getFailedStepKey($workflowInstance),
            default => array_keys($stepsWithCompensation),
        };

        $filteredKeys = array_intersect($keysToCompensate, array_keys($stepsWithCompensation));

        $orderedSteps = [];
        foreach (array_reverse($allSteps) as $step) {
            if (in_array($step->key()->value, $filteredKeys, true)) {
                $orderedSteps[] = $step->key();
            }
        }

        return $orderedSteps;
    }

    /**
     * @return list<string>
     */
    private function getFailedStepKey(WorkflowInstance $workflowInstance): array
    {
        $currentStepKey = $workflowInstance->currentStepKey();

        if (! $currentStepKey instanceof StepKey) {
            return [];
        }

        return [$currentStepKey->value];
    }

    /**
     * Create compensation run records for each step.
     *
     * @param list<StepKey> $stepsToCompensate
     */
    private function createCompensationRuns(
        WorkflowInstance $workflowInstance,
        WorkflowDefinition $workflowDefinition,
        array $stepsToCompensate,
    ): void {
        $order = 1;

        foreach ($stepsToCompensate as $stepToCompensate) {
            $stepDefinition = $workflowDefinition->getStep($stepToCompensate);
            if (! $stepDefinition instanceof StepDefinition) {
                continue;
            }
            if (! $stepDefinition->hasCompensation()) {
                continue;
            }

            $compensationDefinition = $stepDefinition->compensation();
            if (! $compensationDefinition instanceof CompensationDefinition) {
                continue;
            }

            $compensationRun = CompensationRun::create(
                workflowId: $workflowInstance->id,
                stepKey: $stepToCompensate,
                compensationJobClass: $compensationDefinition->jobClass,
                executionOrder: $order,
                maxAttempts: $compensationDefinition->retryConfiguration->maxAttempts,
            );

            $this->compensationRunRepository->save($compensationRun);
            $order++;
        }
    }

    /**
     * Dispatch the next pending compensation job.
     *
     * @throws InvalidStateTransitionException
     */
    private function dispatchNextCompensation(WorkflowInstance $workflowInstance): void
    {
        $nextRun = $this->compensationRunRepository->findNextPending($workflowInstance->id);

        if (! $nextRun instanceof CompensationRun) {
            return;
        }

        $this->dispatchCompensationJob($workflowInstance, $nextRun);
    }

    /**
     * Dispatch a compensation job.
     *
     * @throws InvalidStateTransitionException
     */
    private function dispatchCompensationJob(
        WorkflowInstance $workflowInstance,
        CompensationRun $compensationRun,
    ): void {
        $jobId = JobId::generate();

        $compensationRun->start($jobId);
        $this->compensationRunRepository->save($compensationRun);

        $this->eventDispatcher->dispatch(new CompensationStepStarted(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            stepKey: $compensationRun->stepKey,
            compensationRunId: $compensationRun->id,
            jobId: $jobId,
            attempt: $compensationRun->attempt(),
            executionOrder: $compensationRun->executionOrder,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->jobDispatchService->dispatchCompensationJob(
            $workflowInstance->id,
            $compensationRun->stepKey,
            $compensationRun->id,
            $jobId,
            $compensationRun->compensationJobClass,
        );
    }

    /**
     * Advance compensation after a step completes.
     *
     * @throws InvalidStateTransitionException
     */
    private function advanceCompensation(WorkflowInstance $workflowInstance): void
    {
        if ($this->compensationRunRepository->allTerminal($workflowInstance->id)) {
            if ($this->compensationRunRepository->allSuccessful($workflowInstance->id)) {
                $this->completeCompensation($workflowInstance);
            } else {
                $this->failCompensationFromAllFailed($workflowInstance);
            }

            return;
        }

        $this->dispatchNextCompensation($workflowInstance);
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function completeCompensation(WorkflowInstance $workflowInstance): void
    {
        $workflowInstance->completeCompensation();
        $this->workflowRepository->save($workflowInstance);

        $this->dispatchCompletedEvent($workflowInstance);
    }

    private function dispatchCompletedEvent(WorkflowInstance $workflowInstance): void
    {
        $runs = $this->compensationRunRepository->findByWorkflow($workflowInstance->id);

        $succeeded = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            if ($run->isSucceeded()) {
                $succeeded++;
            } elseif ($run->isSkipped()) {
                $skipped++;
            }
        }

        $startedAt = $workflowInstance->compensationStartedAt();
        $durationMs = $startedAt instanceof CarbonImmutable
            ? (int) CarbonImmutable::now()->diffInMilliseconds($startedAt)
            : 0;

        $this->eventDispatcher->dispatch(new CompensationCompleted(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            totalStepsCompensated: count($runs),
            stepsSucceeded: $succeeded,
            stepsSkipped: $skipped,
            durationMs: $durationMs,
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function failCompensation(
        WorkflowInstance $workflowInstance,
        CompensationRun $compensationRun,
    ): void {
        $workflowInstance->failCompensation();
        $this->workflowRepository->save($workflowInstance);

        $runs = $this->compensationRunRepository->findByWorkflow($workflowInstance->id);
        $completed = 0;

        foreach ($runs as $run) {
            if ($run->isSucceeded() || $run->isSkipped()) {
                $completed++;
            }
        }

        $this->eventDispatcher->dispatch(new CompensationFailed(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            failedAtStepKey: $compensationRun->stepKey,
            stepsCompleted: $completed,
            totalSteps: count($runs),
            failureMessage: $compensationRun->failureMessage(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function failCompensationFromAllFailed(WorkflowInstance $workflowInstance): void
    {
        $failedRuns = $this->compensationRunRepository->findByWorkflowAndStatus(
            $workflowInstance->id,
            CompensationRunStatus::Failed,
        );

        if ($failedRuns !== []) {
            $this->failCompensation($workflowInstance, $failedRuns[0]);
        }
    }
}
