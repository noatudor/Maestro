<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Carbon\CarbonImmutable;
use Deprecated;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryWorkflowRepository implements WorkflowRepository
{
    /** @var array<string, WorkflowInstance> */
    private array $workflows = [];

    /** @var array<string, bool> */
    private array $rowLocks = [];

    public function find(WorkflowId $workflowId): ?WorkflowInstance
    {
        return $this->workflows[$workflowId->value] ?? null;
    }

    /**
     * @throws WorkflowNotFoundException
     */
    public function findOrFail(WorkflowId $workflowId): WorkflowInstance
    {
        $workflow = $this->find($workflowId);

        if (! $workflow instanceof WorkflowInstance) {
            throw WorkflowNotFoundException::withId($workflowId);
        }

        return $workflow;
    }

    public function save(WorkflowInstance $workflowInstance): void
    {
        $this->workflows[$workflowInstance->id->value] = $workflowInstance;
    }

    public function delete(WorkflowId $workflowId): void
    {
        unset($this->workflows[$workflowId->value]);
    }

    public function exists(WorkflowId $workflowId): bool
    {
        return isset($this->workflows[$workflowId->value]);
    }

    /**
     * @return array<string, WorkflowInstance>
     */
    public function findByState(WorkflowState $workflowState): array
    {
        return array_filter(
            $this->workflows,
            static fn (WorkflowInstance $workflowInstance): bool => $workflowInstance->state() === $workflowState,
        );
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function findRunning(): array
    {
        return array_values($this->findByState(WorkflowState::Running));
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function findPaused(): array
    {
        return array_values($this->findByState(WorkflowState::Paused));
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function findFailed(): array
    {
        return array_values($this->findByState(WorkflowState::Failed));
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function findByDefinitionKey(string $definitionKey): array
    {
        return array_values(array_filter(
            $this->workflows,
            static fn (WorkflowInstance $workflowInstance): bool => $workflowInstance->definitionKey->toString() === $definitionKey,
        ));
    }

    /**
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     */
    public function findAndLockForUpdate(WorkflowId $workflowId, int $timeoutSeconds = 5): WorkflowInstance
    {
        $workflow = $this->find($workflowId);

        if (! $workflow instanceof WorkflowInstance) {
            throw WorkflowNotFoundException::withId($workflowId);
        }

        if (isset($this->rowLocks[$workflowId->value]) && $this->rowLocks[$workflowId->value] === true) {
            throw WorkflowLockedException::lockTimeout($workflowId);
        }

        $this->rowLocks[$workflowId->value] = true;

        return $workflow;
    }

    public function acquireApplicationLock(WorkflowId $workflowId, string $lockId): bool
    {
        $workflow = $this->find($workflowId);

        if (! $workflow instanceof WorkflowInstance) {
            return false;
        }

        if ($workflow->isLocked() && $workflow->lockedBy() !== $lockId) {
            return false;
        }

        $workflow->acquireLock($lockId);
        $this->save($workflow);

        return true;
    }

    public function releaseApplicationLock(WorkflowId $workflowId, string $lockId): bool
    {
        $workflow = $this->find($workflowId);

        if (! $workflow instanceof WorkflowInstance) {
            return false;
        }

        $released = $workflow->releaseLock($lockId);

        if ($released) {
            $this->save($workflow);
        }

        return $released;
    }

    public function isLockExpired(WorkflowId $workflowId, int $lockTimeoutSeconds): bool
    {
        $workflow = $this->find($workflowId);

        if (! $workflow instanceof WorkflowInstance || ! $workflow->isLocked()) {
            return false;
        }

        $lockedAt = $workflow->lockedAt();
        if (! $lockedAt instanceof CarbonImmutable) {
            return false;
        }

        $expiresAt = $lockedAt->addSeconds($lockTimeoutSeconds);

        return CarbonImmutable::now()->isAfter($expiresAt);
    }

    public function clearExpiredLocks(int $lockTimeoutSeconds): int
    {
        $count = 0;
        $threshold = CarbonImmutable::now()->subSeconds($lockTimeoutSeconds);

        foreach ($this->workflows as $workflow) {
            $lockedAt = $workflow->lockedAt();
            if ($workflow->isLocked() && $lockedAt !== null && $lockedAt->isBefore($threshold)) {
                $workflow->releaseLock($workflow->lockedBy() ?? '');
                $this->save($workflow);
                $count++;
            }
        }

        return $count;
    }

    #[Deprecated(message: 'Use acquireApplicationLock() instead')]
    public function lockForUpdate(WorkflowId $workflowId, string $lockId): bool
    {
        return $this->acquireApplicationLock($workflowId, $lockId);
    }

    #[Deprecated(message: 'Use releaseApplicationLock() instead')]
    public function releaseLock(WorkflowId $workflowId, string $lockId): bool
    {
        return $this->releaseApplicationLock($workflowId, $lockId);
    }

    /**
     * Release a row lock (for testing only).
     */
    public function releaseRowLock(WorkflowId $workflowId): void
    {
        unset($this->rowLocks[$workflowId->value]);
    }

    /**
     * @template TReturn
     *
     * @param callable(WorkflowInstance): TReturn $callback
     *
     * @return TReturn
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     */
    public function withLockedWorkflow(WorkflowId $workflowId, callable $callback, int $timeoutSeconds = 5): mixed
    {
        $workflowInstance = $this->findAndLockForUpdate($workflowId, $timeoutSeconds);

        try {
            return $callback($workflowInstance);
        } finally {
            $this->releaseRowLock($workflowId);
        }
    }

    /**
     * @return array<string, WorkflowInstance>
     */
    public function all(): array
    {
        return $this->workflows;
    }

    public function count(): int
    {
        return count($this->workflows);
    }

    public function clear(): void
    {
        $this->workflows = [];
    }
}
