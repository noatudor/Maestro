<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\PollAttemptRepository;
use Maestro\Workflow\Contracts\PollingStep;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Config\PollingConfiguration;
use Maestro\Workflow\Domain\Events\PollAborted;
use Maestro\Workflow\Domain\Events\PollAttempted;
use Maestro\Workflow\Domain\Events\PollCompleted;
use Maestro\Workflow\Domain\Events\PollTimedOut;
use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\PollTimeoutPolicy;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\JobId;

/**
 * Handles the result of a poll execution.
 *
 * Evaluates poll results, records poll attempts, checks limits,
 * and either schedules the next poll or finalizes the step.
 */
final readonly class PollResultHandler
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private StepRunRepository $stepRunRepository,
        private PollAttemptRepository $pollAttemptRepository,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Handle a poll result.
     *
     * @throws InvalidStateTransitionException
     */
    public function handlePollResult(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        PollingStep $pollingStep,
        PollResult $pollResult,
        ?JobId $jobId,
        StepOutputStore $stepOutputStore,
    ): void {
        $pollingConfiguration = $pollingStep->pollingConfiguration();
        $newAttemptNumber = $stepRun->pollAttemptCount() + 1;

        $pollAttempt = PollAttempt::create(
            stepRunId: $stepRun->id,
            attemptNumber: $newAttemptNumber,
            jobId: $jobId,
            resultComplete: $pollResult->isComplete(),
            resultContinue: $pollResult->shouldContinue(),
            nextIntervalSeconds: $pollResult->nextIntervalSeconds(),
        );

        $this->pollAttemptRepository->save($pollAttempt);

        $stepRun->recordPollAttempt();
        $this->stepRunRepository->save($stepRun);

        $this->eventDispatcher->dispatch(new PollAttempted(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            attemptNumber: $newAttemptNumber,
            resultComplete: $pollResult->isComplete(),
            resultContinue: $pollResult->shouldContinue(),
            occurredAt: CarbonImmutable::now(),
        ));

        if ($pollResult->isComplete()) {
            $this->handlePollComplete($workflowInstance, $stepRun, $pollResult, $stepOutputStore);

            return;
        }

        if (! $pollResult->shouldContinue()) {
            $this->handlePollAborted($workflowInstance, $stepRun);

            return;
        }

        if ($this->hasExceededLimits($stepRun, $pollingConfiguration)) {
            $this->handlePollTimeout($workflowInstance, $stepRun, $pollingConfiguration, $stepOutputStore);

            return;
        }

        $this->scheduleNextPoll($stepRun, $pollingConfiguration, $pollResult->nextIntervalSeconds());
    }

    /**
     * Check if polling limits have been exceeded.
     */
    public function hasExceededLimits(StepRun $stepRun, PollingConfiguration $pollingConfiguration): bool
    {
        if ($pollingConfiguration->hasExceededMaxAttempts($stepRun->pollAttemptCount())) {
            return true;
        }

        $pollDuration = $stepRun->pollingDuration();

        return $pollDuration !== null && $pollDuration >= $pollingConfiguration->maxDurationSeconds;
    }

    /**
     * Handle successful poll completion.
     *
     * @throws InvalidStateTransitionException
     */
    private function handlePollComplete(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        PollResult $pollResult,
        StepOutputStore $stepOutputStore,
    ): void {
        $output = $pollResult->output();
        if ($output instanceof StepOutput) {
            $stepOutputStore->write($output);
        }

        $stepRun->succeed();
        $this->stepRunRepository->save($stepRun);

        $this->eventDispatcher->dispatch(new PollCompleted(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            totalAttempts: $stepRun->pollAttemptCount(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Handle poll aborted by job logic.
     *
     * @throws InvalidStateTransitionException
     */
    private function handlePollAborted(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
    ): void {
        $stepRun->fail('POLL_ABORTED', 'Polling was aborted by job');
        $this->stepRunRepository->save($stepRun);

        $this->eventDispatcher->dispatch(new PollAborted(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            totalAttempts: $stepRun->pollAttemptCount(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Handle poll timeout.
     *
     * @throws InvalidStateTransitionException
     */
    private function handlePollTimeout(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        PollingConfiguration $pollingConfiguration,
        StepOutputStore $stepOutputStore,
    ): void {
        $policy = $pollingConfiguration->timeoutPolicy;

        if ($policy === PollTimeoutPolicy::ContinueWithDefault && $pollingConfiguration->hasDefaultOutput()) {
            $this->handleContinueWithDefault($workflowInstance, $stepRun, $pollingConfiguration, $stepOutputStore);

            return;
        }

        $stepRun->timeout('POLL_TIMEOUT', 'Polling exceeded time or attempt limits');
        $this->stepRunRepository->save($stepRun);

        $this->eventDispatcher->dispatch(new PollTimedOut(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            totalAttempts: $stepRun->pollAttemptCount(),
            policy: $policy,
            occurredAt: CarbonImmutable::now(),
        ));

        if ($policy === PollTimeoutPolicy::PauseWorkflow) {
            $workflowInstance->pause('Polling step timed out');
            $this->workflowRepository->save($workflowInstance);
        }
    }

    /**
     * Handle continue with default output on timeout.
     *
     * @throws InvalidStateTransitionException
     */
    private function handleContinueWithDefault(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        PollingConfiguration $pollingConfiguration,
        StepOutputStore $stepOutputStore,
    ): void {
        /** @var class-string<StepOutput> $outputClass */
        $outputClass = $pollingConfiguration->defaultOutputClass;

        if (method_exists($outputClass, 'default')) {
            /** @var StepOutput $defaultOutput */
            $defaultOutput = $outputClass::default();
            $stepOutputStore->write($defaultOutput);
        }

        $stepRun->succeed();
        $this->stepRunRepository->save($stepRun);

        $this->eventDispatcher->dispatch(new PollCompleted(
            workflowId: $workflowInstance->id,
            stepRunId: $stepRun->id,
            stepKey: $stepRun->stepKey,
            totalAttempts: $stepRun->pollAttemptCount(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Schedule the next poll attempt.
     */
    private function scheduleNextPoll(
        StepRun $stepRun,
        PollingConfiguration $pollingConfiguration,
        ?int $overrideIntervalSeconds,
    ): void {
        $interval = $pollingConfiguration->calculateIntervalForAttempt(
            $stepRun->pollAttemptCount() + 1,
            $overrideIntervalSeconds,
        );

        $nextPollAt = CarbonImmutable::now()->addSeconds($interval);
        $stepRun->scheduleNextPoll($nextPollAt);
        $this->stepRunRepository->save($stepRun);
    }
}
