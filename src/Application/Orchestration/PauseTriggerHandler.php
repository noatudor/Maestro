<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\TriggerPayloadRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\TriggerReceived;
use Maestro\Workflow\Domain\Events\TriggerTimedOut;
use Maestro\Workflow\Domain\Events\TriggerValidationFailed;
use Maestro\Workflow\Domain\Events\WorkflowAutoResumed;
use Maestro\Workflow\Domain\Events\WorkflowAwaitingTrigger;
use Maestro\Workflow\Domain\TriggerPayloadRecord;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Handles pause trigger operations for workflows.
 *
 * Responsible for:
 * - Pausing workflows after step completion when a pause trigger is configured
 * - Processing incoming triggers and validating resume conditions
 * - Handling trigger timeouts according to configured policy
 * - Processing scheduled auto-resumes
 */
final readonly class PauseTriggerHandler
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private TriggerPayloadRepository $triggerPayloadRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private ConditionEvaluator $conditionEvaluator,
        private StepOutputStoreFactory $stepOutputStoreFactory,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Pause a workflow for a trigger after step completion.
     *
     * @throws InvalidStateTransitionException
     */
    public function pauseForTrigger(
        WorkflowInstance $workflowInstance,
        StepKey $afterStepKey,
        PauseTriggerDefinition $pauseTriggerDefinition,
    ): void {
        $now = CarbonImmutable::now();
        $timeoutAt = $now->addSeconds($pauseTriggerDefinition->timeoutSeconds);
        $scheduledResumeAt = $pauseTriggerDefinition->hasScheduledResume()
            ? $now->addSeconds($pauseTriggerDefinition->scheduledResumeSeconds ?? 0)
            : null;

        $workflowInstance->pauseForTrigger(
            triggerKey: $pauseTriggerDefinition->triggerKey,
            timeoutAt: $timeoutAt,
            scheduledResumeAt: $scheduledResumeAt,
            reason: 'Awaiting trigger: '.$pauseTriggerDefinition->triggerKey,
        );

        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowAwaitingTrigger(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            afterStepKey: $afterStepKey,
            triggerKey: $pauseTriggerDefinition->triggerKey,
            timeoutAt: $timeoutAt,
            scheduledResumeAt: $scheduledResumeAt,
            occurredAt: $now,
        ));
    }

    /**
     * Process an incoming trigger for a paused workflow.
     *
     * @return bool True if workflow was resumed, false if validation failed
     *
     * @throws InvalidStateTransitionException
     * @throws WorkflowNotFoundException
     * @throws ConditionEvaluationException
     * @throws DefinitionNotFoundException
     */
    public function processTrigger(
        WorkflowId $workflowId,
        string $triggerKey,
        TriggerPayload $triggerPayload,
        ?string $sourceIp = null,
        ?string $sourceIdentifier = null,
    ): bool {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if (! $workflowInstance->isAwaitingTriggerKey($triggerKey)) {
            return false;
        }

        $stepDefinition = $this->getCurrentStepDefinition($workflowInstance);
        $pauseTrigger = $stepDefinition?->pauseTrigger();

        if (! $pauseTrigger instanceof PauseTriggerDefinition) {
            return false;
        }

        if ($pauseTrigger->hasResumeCondition() && $pauseTrigger->resumeConditionClass !== null) {
            $stepOutputStore = $this->stepOutputStoreFactory->forWorkflow($workflowId);
            $shouldResume = $this->conditionEvaluator->evaluateResumeCondition(
                $pauseTrigger->resumeConditionClass,
                $triggerPayload,
                $stepOutputStore,
            );

            if (! $shouldResume->shouldResume()) {
                $this->eventDispatcher->dispatch(new TriggerValidationFailed(
                    workflowId: $workflowInstance->id,
                    definitionKey: $workflowInstance->definitionKey,
                    definitionVersion: $workflowInstance->definitionVersion,
                    triggerKey: $triggerKey,
                    payload: $triggerPayload,
                    failureReason: $shouldResume->rejectionReason() ?? 'Resume condition not met',
                    sourceIp: $sourceIp,
                    sourceIdentifier: $sourceIdentifier,
                    occurredAt: CarbonImmutable::now(),
                ));

                return false;
            }
        }

        $triggerPayloadRecord = TriggerPayloadRecord::create(
            workflowId: $workflowId,
            triggerKey: $triggerKey,
            sourceIp: $sourceIp,
            sourceIdentifier: $sourceIdentifier,
            payload: $triggerPayload,
        );

        $this->triggerPayloadRepository->save($triggerPayloadRecord);

        $workflowInstance->resumeFromTrigger();
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new TriggerReceived(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            triggerKey: $triggerKey,
            payloadId: $triggerPayloadRecord->id,
            payload: $triggerPayload,
            sourceIp: $sourceIp,
            sourceIdentifier: $sourceIdentifier,
            occurredAt: CarbonImmutable::now(),
        ));

        return true;
    }

    /**
     * Handle a timed-out trigger according to the configured policy.
     *
     * @throws InvalidStateTransitionException
     */
    public function handleTimeout(
        WorkflowInstance $workflowInstance,
        PauseTriggerDefinition $pauseTriggerDefinition,
    ): void {
        $policy = $pauseTriggerDefinition->timeoutPolicy;
        $triggerKey = $workflowInstance->awaitingTriggerKey() ?? $pauseTriggerDefinition->triggerKey;

        match ($policy) {
            TriggerTimeoutPolicy::FailWorkflow => $this->failWorkflowOnTimeout($workflowInstance, $triggerKey),
            TriggerTimeoutPolicy::AutoResume => $this->autoResumeOnTimeout($workflowInstance, $triggerKey),
            TriggerTimeoutPolicy::SendReminder => $this->sendReminderOnTimeout($workflowInstance, $triggerKey, $pauseTriggerDefinition),
            TriggerTimeoutPolicy::ExtendTimeout => $this->extendTimeoutOnTimeout($workflowInstance, $pauseTriggerDefinition),
        };

        $this->eventDispatcher->dispatch(new TriggerTimedOut(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            triggerKey: $triggerKey,
            appliedPolicy: $policy,
            timeoutAt: $workflowInstance->triggerTimeoutAt() ?? CarbonImmutable::now(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Process a scheduled auto-resume.
     *
     * @throws InvalidStateTransitionException
     */
    public function processScheduledResume(WorkflowInstance $workflowInstance): void
    {
        $triggerKey = $workflowInstance->awaitingTriggerKey() ?? 'scheduled-resume';
        $scheduledAt = $workflowInstance->scheduledResumeAt() ?? CarbonImmutable::now();

        $workflowInstance->resumeFromTrigger();
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowAutoResumed(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            triggerKey: $triggerKey,
            scheduledAt: $scheduledAt,
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function failWorkflowOnTimeout(WorkflowInstance $workflowInstance, string $triggerKey): void
    {
        $workflowInstance->fail(
            code: 'TRIGGER_TIMEOUT',
            message: sprintf("Trigger '%s' timed out", $triggerKey),
        );
        $this->workflowRepository->save($workflowInstance);
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function autoResumeOnTimeout(WorkflowInstance $workflowInstance, string $triggerKey): void
    {
        $triggerPayloadRecord = TriggerPayloadRecord::create(
            workflowId: $workflowInstance->id,
            triggerKey: $triggerKey,
            payload: TriggerPayload::empty(),
        );

        $this->triggerPayloadRepository->save($triggerPayloadRecord);

        $workflowInstance->resumeFromTrigger();
        $this->workflowRepository->save($workflowInstance);
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function sendReminderOnTimeout(
        WorkflowInstance $workflowInstance,
        string $triggerKey,
        PauseTriggerDefinition $pauseTriggerDefinition,
    ): void {
        // The reminder itself would be handled by an event listener.
        // Here we just extend the timeout by the reminder interval.
        if ($pauseTriggerDefinition->reminderIntervalSeconds !== null) {
            $newTimeout = CarbonImmutable::now()->addSeconds($pauseTriggerDefinition->reminderIntervalSeconds);
            $workflowInstance->pauseForTrigger(
                triggerKey: $triggerKey,
                timeoutAt: $newTimeout,
                reason: $workflowInstance->pausedReason(),
            );
            $this->workflowRepository->save($workflowInstance);
        }
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function extendTimeoutOnTimeout(
        WorkflowInstance $workflowInstance,
        PauseTriggerDefinition $pauseTriggerDefinition,
    ): void {
        $newTimeout = CarbonImmutable::now()->addSeconds($pauseTriggerDefinition->timeoutSeconds);
        $workflowInstance->pauseForTrigger(
            triggerKey: $pauseTriggerDefinition->triggerKey,
            timeoutAt: $newTimeout,
            reason: $workflowInstance->pausedReason(),
        );
        $this->workflowRepository->save($workflowInstance);
    }

    /**
     * @throws DefinitionNotFoundException
     */
    private function getCurrentStepDefinition(WorkflowInstance $workflowInstance): ?StepDefinition
    {
        $currentStepKey = $workflowInstance->currentStepKey();
        if (! $currentStepKey instanceof StepKey) {
            return null;
        }

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        return $workflowDefinition->getStep($currentStepKey);
    }
}
