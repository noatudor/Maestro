<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job\Middleware;

use Closure;
use Maestro\Workflow\Application\Job\CompensationJob;
use Maestro\Workflow\Application\Orchestration\CompensationExecutor;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Throwable;

/**
 * Middleware that handles compensation job lifecycle.
 *
 * Records success and failure of compensation jobs and
 * triggers the next compensation step.
 */
final readonly class CompensationJobMiddleware
{
    public function __construct(
        private CompensationExecutor $compensationExecutor,
    ) {}

    /**
     * Handle the compensation job execution.
     *
     * @param Closure(CompensationJob): void $next
     *
     * @throws InvalidStateTransitionException
     * @throws WorkflowNotFoundException
     * @throws Throwable
     */
    public function handle(CompensationJob $compensationJob, Closure $next): void
    {
        try {
            $next($compensationJob);

            $this->compensationExecutor->recordSuccess($compensationJob->compensationRunId);
        } catch (Throwable $exception) {
            $failureMessage = $this->truncateMessage($exception->getMessage());
            $failureTrace = $this->truncateTrace($exception->getTraceAsString());

            $this->compensationExecutor->recordFailure(
                $compensationJob->compensationRunId,
                $failureMessage,
                $failureTrace,
            );

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

    private function truncateTrace(string $trace, int $maxLength = 10000): string
    {
        if (mb_strlen($trace) <= $maxLength) {
            return $trace;
        }

        return mb_substr($trace, 0, $maxLength - 3).'...';
    }
}
