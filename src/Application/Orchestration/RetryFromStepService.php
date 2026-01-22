<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\RetryFromStepCompleted;
use Maestro\Workflow\Domain\Events\RetryFromStepInitiated;
use Maestro\Workflow\Domain\Events\StepRunSuperseded;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\RetryFromStepRequest;
use Maestro\Workflow\ValueObjects\RetryFromStepResult;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;

/**
 * Service for handling retry-from-step operations.
 *
 * Enables re-running a workflow from any previously completed step,
 * superseding step runs and clearing outputs from the retry point onwards.
 */
final readonly class RetryFromStepService
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private StepRunRepository $stepRunRepository,
        private StepOutputRepository $stepOutputRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private StepDispatcher $stepDispatcher,
        private CompensationExecutor $compensationExecutor,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Execute a retry-from-step operation.
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws StepNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws ConditionEvaluationException
     */
    public function execute(RetryFromStepRequest $retryFromStepRequest): RetryFromStepResult
    {
        $workflowInstance = $this->workflowRepository->findOrFail($retryFromStepRequest->workflowId);

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $this->validateRetryRequest($workflowDefinition, $retryFromStepRequest);

        $affectedStepKeys = $this->getAffectedStepKeys($workflowDefinition, $retryFromStepRequest->retryFromStepKey);

        $this->eventDispatcher->dispatch(new RetryFromStepInitiated(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            retryFromStepKey: $retryFromStepRequest->retryFromStepKey,
            retryMode: $retryFromStepRequest->retryMode,
            affectedStepKeys: array_map(
                static fn (StepKey $stepKey): string => $stepKey->value,
                $affectedStepKeys,
            ),
            initiatedBy: $retryFromStepRequest->initiatedBy,
            reason: $retryFromStepRequest->reason,
            occurredAt: CarbonImmutable::now(),
        ));

        $compensationExecuted = false;
        if ($retryFromStepRequest->requiresCompensation()) {
            $compensationExecuted = $this->executeCompensation($workflowInstance, $affectedStepKeys);
        }

        $newStepRunId = StepRunId::generate();
        $supersededStepRunIds = $this->supersedeAffectedStepRuns(
            $workflowInstance,
            $affectedStepKeys,
            $newStepRunId,
        );

        $clearedOutputStepKeys = $this->clearAffectedOutputs($workflowInstance, $affectedStepKeys);

        $workflowInstance->advanceToStep($retryFromStepRequest->retryFromStepKey);
        $workflowInstance->retry();
        $this->workflowRepository->save($workflowInstance);

        $stepDefinition = $workflowDefinition->getStep($retryFromStepRequest->retryFromStepKey);
        if (! $stepDefinition instanceof StepDefinition) {
            throw StepNotFoundException::withKey($retryFromStepRequest->retryFromStepKey);
        }

        $newStepRun = $this->dispatchRetryStep(
            $workflowInstance,
            $stepDefinition,
        );

        $this->eventDispatcher->dispatch(new RetryFromStepCompleted(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            retryFromStepKey: $retryFromStepRequest->retryFromStepKey,
            newStepRunId: $newStepRun->id,
            retryMode: $retryFromStepRequest->retryMode,
            supersededStepRunCount: count($supersededStepRunIds),
            clearedOutputCount: count($clearedOutputStepKeys),
            compensationExecuted: $compensationExecuted,
            occurredAt: CarbonImmutable::now(),
        ));

        $workflowInstance = $this->workflowRepository->findOrFail($retryFromStepRequest->workflowId);

        return RetryFromStepResult::create(
            workflowInstance: $workflowInstance,
            retryFromStepKey: $retryFromStepRequest->retryFromStepKey,
            newStepRunId: $newStepRun->id,
            supersededStepRunIds: $supersededStepRunIds,
            clearedOutputStepKeys: $clearedOutputStepKeys,
            compensationExecuted: $compensationExecuted,
        );
    }

    /**
     * @throws StepNotFoundException
     */
    private function validateRetryRequest(
        WorkflowDefinition $workflowDefinition,
        RetryFromStepRequest $retryFromStepRequest,
    ): void {
        if (! $workflowDefinition->hasStep($retryFromStepRequest->retryFromStepKey)) {
            throw StepNotFoundException::withKey($retryFromStepRequest->retryFromStepKey);
        }
    }

    /**
     * Get all step keys from the retry point onwards (including the retry step).
     *
     * @return list<StepKey>
     */
    private function getAffectedStepKeys(
        WorkflowDefinition $workflowDefinition,
        StepKey $retryFromStepKey,
    ): array {
        $stepCollection = $workflowDefinition->steps();
        $stepsAfter = $stepCollection->stepsAfter($retryFromStepKey);

        $affectedKeys = [$retryFromStepKey];

        foreach ($stepsAfter as $stepAfter) {
            $affectedKeys[] = $stepAfter->key();
        }

        return $affectedKeys;
    }

    /**
     * Execute compensation for affected steps.
     *
     * Compensation is executed synchronously before the retry continues.
     * Only steps with compensation defined are compensated.
     *
     * @param list<StepKey> $affectedStepKeys
     *
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws WorkflowNotFoundException
     */
    private function executeCompensation(
        WorkflowInstance $workflowInstance,
        array $affectedStepKeys,
    ): bool {
        $this->compensationExecutor->initiate(
            workflowId: $workflowInstance->id,
            stepKeys: $affectedStepKeys,
            initiatedBy: 'retry-from-step',
            reason: 'Compensation before retry-from-step',
            scope: CompensationScope::FromStep,
        );

        return true;
    }

    /**
     * Mark all active step runs for affected steps as superseded.
     *
     * @param list<StepKey> $affectedStepKeys
     *
     * @return list<StepRunId> IDs of superseded step runs
     */
    private function supersedeAffectedStepRuns(
        WorkflowInstance $workflowInstance,
        array $affectedStepKeys,
        StepRunId $stepRunId,
    ): array {
        $supersededIds = [];

        $activeStepRuns = $this->stepRunRepository->findLatestActiveByStepKeys(
            $workflowInstance->id,
            $affectedStepKeys,
        );

        foreach ($activeStepRuns as $activeStepRun) {
            $wasSuperseded = $this->stepRunRepository->markAsSuperseded(
                $activeStepRun->id,
                $stepRunId,
            );

            if ($wasSuperseded) {
                $supersededIds[] = $activeStepRun->id;

                $this->eventDispatcher->dispatch(new StepRunSuperseded(
                    workflowId: $workflowInstance->id,
                    stepRunId: $activeStepRun->id,
                    stepKey: $activeStepRun->stepKey,
                    attempt: $activeStepRun->attempt,
                    supersededById: $stepRunId,
                    occurredAt: CarbonImmutable::now(),
                ));
            }
        }

        return $supersededIds;
    }

    /**
     * Clear outputs for affected steps.
     *
     * @param list<StepKey> $affectedStepKeys
     *
     * @return list<StepKey> Step keys whose outputs were cleared
     */
    private function clearAffectedOutputs(
        WorkflowInstance $workflowInstance,
        array $affectedStepKeys,
    ): array {
        $stepKeyStrings = array_map(
            static fn (StepKey $stepKey): string => $stepKey->value,
            $affectedStepKeys,
        );

        $deletedCount = $this->stepOutputRepository->deleteByStepKeys(
            $workflowInstance->id,
            $stepKeyStrings,
        );

        if ($deletedCount > 0) {
            return $affectedStepKeys;
        }

        return [];
    }

    /**
     * Dispatch the retry step using the step dispatcher.
     *
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    private function dispatchRetryStep(
        WorkflowInstance $workflowInstance,
        StepDefinition $stepDefinition,
    ): StepRun {
        return $this->stepDispatcher->retryStep($workflowInstance, $stepDefinition);
    }
}
