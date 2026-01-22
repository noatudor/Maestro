<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Domain\Events\JobFailed;
use Maestro\Workflow\Domain\Events\JobStarted;
use Maestro\Workflow\Domain\Events\JobSucceeded;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Throwable;

/**
 * Middleware that tracks job lifecycle transitions.
 *
 * Records job start, completion, and failure in the job ledger.
 * Captures worker ID and runtime metrics for operational transparency.
 */
final readonly class JobLifecycleMiddleware
{
    public function __construct(
        private JobLedgerRepository $jobLedgerRepository,
        private Dispatcher $eventDispatcher,
        private ?string $workerId = null,
    ) {}

    /**
     * Handle the job execution with lifecycle tracking.
     *
     * @param Closure(OrchestratedJob): void $next
     *
     * @throws InvalidStateTransitionException
     */
    public function handle(OrchestratedJob $orchestratedJob, Closure $next): void
    {
        $jobRecord = $this->jobLedgerRepository->findByJobUuid($orchestratedJob->jobUuid);

        if (! $jobRecord instanceof JobRecord) {
            $next($orchestratedJob);

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
            $next($orchestratedJob);

            $jobRecord = $this->jobLedgerRepository->findByJobUuid($orchestratedJob->jobUuid);
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
        } catch (Throwable $exception) {
            $jobRecord = $this->jobLedgerRepository->findByJobUuid($orchestratedJob->jobUuid);
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
