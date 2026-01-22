<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Application\Orchestration\PollResultHandler;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\PollingStep;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\JobFailed;
use Maestro\Workflow\Domain\Events\JobStarted;
use Maestro\Workflow\Domain\Events\JobSucceeded;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Throwable;

/**
 * Middleware that handles polling job execution.
 *
 * Executes the polling job, captures the PollResult, and passes it to
 * PollResultHandler for processing. Triggers workflow advancer if
 * polling is complete.
 */
final readonly class PollingJobMiddleware
{
    public function __construct(
        private JobLedgerRepository $jobLedgerRepository,
        private StepRunRepository $stepRunRepository,
        private WorkflowRepository $workflowRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private PollResultHandler $pollResultHandler,
        private StepOutputStoreFactory $stepOutputStoreFactory,
        private WorkflowAdvancer $workflowAdvancer,
        private Dispatcher $eventDispatcher,
        private ?string $workerId = null,
    ) {}

    /**
     * Handle the polling job execution.
     *
     * @param Closure(PollingJob): PollResult $next
     *
     * @throws InvalidStateTransitionException
     */
    public function handle(PollingJob $pollingJob, Closure $next): void
    {
        $jobRecord = $this->jobLedgerRepository->findByJobUuid($pollingJob->jobUuid);

        if (! $jobRecord instanceof JobRecord) {
            $next($pollingJob);

            return;
        }

        if ($jobRecord->isTerminal()) {
            return;
        }

        $jobRecord->start($this->workerId);
        $this->jobLedgerRepository->save($jobRecord);

        $this->eventDispatcher->dispatch(new JobStarted(
            workflowId: $jobRecord->workflowId,
            stepRunId: $jobRecord->stepRunId,
            jobId: $jobRecord->id,
            jobUuid: $jobRecord->jobUuid,
            jobClass: $jobRecord->jobClass,
            attempt: $jobRecord->attempt(),
            workerId: $this->workerId,
            occurredAt: CarbonImmutable::now(),
        ));

        try {
            /** @var PollResult $pollResult */
            $pollResult = $next($pollingJob);

            $jobRecord = $this->jobLedgerRepository->findByJobUuid($pollingJob->jobUuid);
            if ($jobRecord instanceof JobRecord && $jobRecord->status() === JobState::Running) {
                $jobRecord->succeed();
                $this->jobLedgerRepository->save($jobRecord);

                $this->eventDispatcher->dispatch(new JobSucceeded(
                    workflowId: $jobRecord->workflowId,
                    stepRunId: $jobRecord->stepRunId,
                    jobId: $jobRecord->id,
                    jobUuid: $jobRecord->jobUuid,
                    jobClass: $jobRecord->jobClass,
                    attempt: $jobRecord->attempt(),
                    runtimeMs: $jobRecord->runtimeMs(),
                    occurredAt: CarbonImmutable::now(),
                ));
            }

            $this->handlePollResult($pollingJob, $pollResult, $jobRecord);
        } catch (Throwable $exception) {
            $jobRecord = $this->jobLedgerRepository->findByJobUuid($pollingJob->jobUuid);
            if ($jobRecord instanceof JobRecord && $jobRecord->status() === JobState::Running) {
                $failureClass = $exception::class;
                $failureMessage = $this->truncateMessage($exception->getMessage());

                $jobRecord->fail(
                    failureClass: $failureClass,
                    failureMessage: $failureMessage,
                    failureTrace: $this->truncateTrace($exception->getTraceAsString()),
                );
                $this->jobLedgerRepository->save($jobRecord);

                $this->eventDispatcher->dispatch(new JobFailed(
                    workflowId: $jobRecord->workflowId,
                    stepRunId: $jobRecord->stepRunId,
                    jobId: $jobRecord->id,
                    jobUuid: $jobRecord->jobUuid,
                    jobClass: $jobRecord->jobClass,
                    attempt: $jobRecord->attempt(),
                    failureClass: $failureClass,
                    failureMessage: $failureMessage,
                    runtimeMs: $jobRecord->runtimeMs(),
                    occurredAt: CarbonImmutable::now(),
                ));
            }

            throw $exception;
        }
    }

    /**
     * @throws StepNotFoundException
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws WorkflowLockedException
     */
    private function handlePollResult(
        PollingJob $pollingJob,
        PollResult $pollResult,
        ?JobRecord $jobRecord,
    ): void {
        $stepRun = $this->stepRunRepository->findOrFail($pollingJob->stepRunId);

        if (! $stepRun->isPolling()) {
            return;
        }

        $workflowInstance = $this->workflowRepository->findOrFail($pollingJob->workflowId);

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $stepDefinition = $workflowDefinition->steps()->findByKey($stepRun->stepKey);

        if (! $stepDefinition instanceof PollingStep) {
            return;
        }

        $jobId = $jobRecord instanceof JobRecord ? $jobRecord->id : null;
        $stepOutputStore = $this->stepOutputStoreFactory->forWorkflow($workflowInstance->id);

        $this->pollResultHandler->handlePollResult(
            $workflowInstance,
            $stepRun,
            $stepDefinition,
            $pollResult,
            $jobId,
            $stepOutputStore,
        );

        $stepRun = $this->stepRunRepository->findOrFail($pollingJob->stepRunId);

        if ($stepRun->isTerminal()) {
            $this->workflowAdvancer->evaluate($pollingJob->workflowId);
        }
    }

    private function truncateMessage(string $message, int $maxLength = 65535): string
    {
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }

        return mb_substr($message, 0, $maxLength - 3).'...';
    }

    private function truncateTrace(string $trace, int $maxLength = 65535): string
    {
        if (mb_strlen($trace) <= $maxLength) {
            return $trace;
        }

        return mb_substr($trace, 0, $maxLength - 3).'...';
    }
}
