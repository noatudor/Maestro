<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Carbon\CarbonImmutable;
use Deprecated;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface WorkflowRepository
{
    public function find(WorkflowId $workflowId): ?WorkflowInstance;

    public function save(WorkflowInstance $workflowInstance): void;

    public function delete(WorkflowId $workflowId): void;

    /**
     * @return array<string, WorkflowInstance>
     */
    public function findByState(WorkflowState $workflowState): array;

    /**
     * @throws WorkflowNotFoundException
     */
    public function findOrFail(WorkflowId $workflowId): WorkflowInstance;

    public function exists(WorkflowId $workflowId): bool;

    /**
     * @return list<WorkflowInstance>
     */
    public function findRunning(): array;

    /**
     * @return list<WorkflowInstance>
     */
    public function findPaused(): array;

    /**
     * @return list<WorkflowInstance>
     */
    public function findFailed(): array;

    /**
     * @return list<WorkflowInstance>
     */
    public function findByDefinitionKey(string $definitionKey): array;

    /**
     * Find terminal workflows completed before a threshold.
     *
     * Terminal workflows are those in SUCCEEDED, FAILED, or CANCELLED state.
     *
     * @return list<WorkflowInstance>
     */
    public function findTerminalBefore(CarbonImmutable $threshold): array;

    /**
     * Acquire a pessimistic lock on the workflow row using SELECT FOR UPDATE.
     *
     * This method will wait up to the specified timeout for the lock to become available.
     * If the lock cannot be acquired within the timeout, it throws WorkflowLockedException.
     *
     * @param int $timeoutSeconds Maximum time to wait for lock acquisition
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     */
    public function findAndLockForUpdate(WorkflowId $workflowId, int $timeoutSeconds = 5): WorkflowInstance;

    /**
     * Acquire an application-level lock on a workflow.
     *
     * This sets the locked_by and locked_at columns atomically.
     * Returns false if the workflow is already locked by another process.
     */
    public function acquireApplicationLock(WorkflowId $workflowId, string $lockId): bool;

    /**
     * Release an application-level lock on a workflow.
     *
     * Only releases the lock if it was acquired by the same lock ID.
     */
    public function releaseApplicationLock(WorkflowId $workflowId, string $lockId): bool;

    /**
     * Check if a workflow's application lock has expired.
     *
     * A lock is considered expired if locked_at + lockTimeoutSeconds < now.
     */
    public function isLockExpired(WorkflowId $workflowId, int $lockTimeoutSeconds): bool;

    /**
     * Clear expired application locks.
     *
     * This is used by zombie detection to clean up stale locks from crashed processes.
     *
     * @return int Number of locks cleared
     */
    public function clearExpiredLocks(int $lockTimeoutSeconds): int;

    #[Deprecated(message: 'Use acquireApplicationLock() instead')]
    public function lockForUpdate(WorkflowId $workflowId, string $lockId): bool;

    #[Deprecated(message: 'Use releaseApplicationLock() instead')]
    public function releaseLock(WorkflowId $workflowId, string $lockId): bool;

    /**
     * Execute a callback with a workflow locked for update within a transaction.
     *
     * This method:
     * 1. Starts a database transaction
     * 2. Acquires a row lock on the workflow using SELECT FOR UPDATE
     * 3. Executes the callback with the locked workflow
     * 4. Commits the transaction (or rolls back on exception)
     *
     * @template TReturn
     *
     * @param callable(WorkflowInstance): TReturn $callback
     * @param int $timeoutSeconds Maximum time to wait for lock acquisition
     *
     * @return TReturn
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     */
    public function withLockedWorkflow(WorkflowId $workflowId, callable $callback, int $timeoutSeconds = 5): mixed;

    /**
     * Find workflows with trigger timeout before a given time.
     *
     * @return list<WorkflowInstance>
     */
    public function findByStateAndTriggerTimeoutBefore(
        WorkflowState $workflowState,
        CarbonImmutable $before,
        int $limit = 100,
    ): array;

    /**
     * Find workflows with scheduled resume before a given time.
     *
     * @return list<WorkflowInstance>
     */
    public function findByStateAndScheduledResumeBefore(
        WorkflowState $workflowState,
        CarbonImmutable $before,
        int $limit = 100,
    ): array;

    /**
     * Find a workflow that is awaiting a specific trigger key.
     */
    public function findByAwaitingTriggerKey(string $triggerKey): ?WorkflowInstance;
}
