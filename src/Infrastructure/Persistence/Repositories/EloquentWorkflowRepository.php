<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Carbon\CarbonImmutable;
use Deprecated;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Exceptions\InvalidDefinitionVersionException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\WorkflowHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\WorkflowModel;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentWorkflowRepository implements WorkflowRepository
{
    public function __construct(
        private WorkflowHydrator $workflowHydrator,
        private Connection $connection,
    ) {}

    /**
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function find(WorkflowId $workflowId): ?WorkflowInstance
    {
        $model = WorkflowModel::query()->find($workflowId->value);

        if ($model === null) {
            return null;
        }

        return $this->workflowHydrator->toDomain($model);
    }

    /**
     * @throws WorkflowNotFoundException
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
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
        $existingModel = WorkflowModel::query()->find($workflowInstance->id->value);

        if ($existingModel !== null) {
            $this->workflowHydrator->updateFromDomain($existingModel, $workflowInstance);
            $existingModel->save();

            return;
        }

        $workflowModel = $this->workflowHydrator->fromDomain($workflowInstance);
        $workflowModel->save();
    }

    public function delete(WorkflowId $workflowId): void
    {
        WorkflowModel::query()
            ->where('id', $workflowId->value)
            ->delete();
    }

    /**
     * @return array<string, WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findByState(WorkflowState $workflowState): array
    {
        $models = WorkflowModel::query()
            ->where('state', $workflowState->value)
            ->get();

        $result = [];
        foreach ($models as $model) {
            $result[$model->id] = $this->workflowHydrator->toDomain($model);
        }

        return $result;
    }

    public function exists(WorkflowId $workflowId): bool
    {
        return WorkflowModel::query()
            ->where('id', $workflowId->value)
            ->exists();
    }

    /**
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findRunning(): array
    {
        return $this->findAllByState(WorkflowState::Running);
    }

    /**
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findPaused(): array
    {
        return $this->findAllByState(WorkflowState::Paused);
    }

    /**
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findFailed(): array
    {
        return $this->findAllByState(WorkflowState::Failed);
    }

    /**
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findByDefinitionKey(string $definitionKey): array
    {
        $models = WorkflowModel::query()
            ->forDefinition($definitionKey)
            ->get();

        return $this->hydrateModels($models->all());
    }

    /**
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findTerminalBefore(CarbonImmutable $threshold): array
    {
        $terminalStates = [
            WorkflowState::Succeeded->value,
            WorkflowState::Failed->value,
            WorkflowState::Cancelled->value,
        ];

        $models = WorkflowModel::query()
            ->whereIn('state', $terminalStates)
            ->where('updated_at', '<', $threshold)
            ->get();

        return $this->hydrateModels($models->all());
    }

    /**
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function findAndLockForUpdate(WorkflowId $workflowId, int $timeoutSeconds = 5): WorkflowInstance
    {
        $this->setLockTimeout($timeoutSeconds);

        try {
            $model = WorkflowModel::query()
                ->where('id', $workflowId->value)
                ->lockForUpdate()
                ->first();
        } catch (QueryException $e) {
            if ($this->isLockTimeoutException($e)) {
                throw WorkflowLockedException::lockTimeout($workflowId);
            }

            throw $e;
        }

        if ($model === null) {
            throw WorkflowNotFoundException::withId($workflowId);
        }

        return $this->workflowHydrator->toDomain($model);
    }

    public function acquireApplicationLock(WorkflowId $workflowId, string $lockId): bool
    {
        $now = CarbonImmutable::now();

        $affected = WorkflowModel::query()
            ->where('id', $workflowId->value)
            ->whereNull('locked_by')
            ->update([
                'locked_by' => $lockId,
                'locked_at' => $now,
            ]);

        return $affected > 0;
    }

    public function releaseApplicationLock(WorkflowId $workflowId, string $lockId): bool
    {
        $affected = WorkflowModel::query()
            ->where('id', $workflowId->value)
            ->where('locked_by', $lockId)
            ->update([
                'locked_by' => null,
                'locked_at' => null,
            ]);

        return $affected > 0;
    }

    public function isLockExpired(WorkflowId $workflowId, int $lockTimeoutSeconds): bool
    {
        $model = WorkflowModel::query()
            ->where('id', $workflowId->value)
            ->first();

        if ($model === null || $model->locked_at === null) {
            return false;
        }

        $expiresAt = $model->locked_at->addSeconds($lockTimeoutSeconds);

        return CarbonImmutable::now()->isAfter($expiresAt);
    }

    public function clearExpiredLocks(int $lockTimeoutSeconds): int
    {
        $expiryThreshold = CarbonImmutable::now()->subSeconds($lockTimeoutSeconds);

        return WorkflowModel::query()
            ->whereNotNull('locked_by')
            ->where('locked_at', '<', $expiryThreshold)
            ->update([
                'locked_by' => null,
                'locked_at' => null,
            ]);
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
     * @template TReturn
     *
     * @param callable(WorkflowInstance): TReturn $callback
     *
     * @return TReturn
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    public function withLockedWorkflow(WorkflowId $workflowId, callable $callback, int $timeoutSeconds = 5): mixed
    {
        return $this->connection->transaction(function () use ($workflowId, $callback, $timeoutSeconds) {
            $workflowInstance = $this->findAndLockForUpdate($workflowId, $timeoutSeconds);

            return $callback($workflowInstance);
        });
    }

    private function setLockTimeout(int $timeoutSeconds): void
    {
        $driver = $this->connection->getDriverName();

        match ($driver) {
            'mysql' => $this->connection->statement('SET innodb_lock_wait_timeout = '.$timeoutSeconds),
            'pgsql' => $this->connection->statement(sprintf("SET lock_timeout = '%ds'", $timeoutSeconds)),
            default => null,
        };
    }

    private function isLockTimeoutException(QueryException $queryException): bool
    {
        $driver = $this->connection->getDriverName();

        return match ($driver) {
            'mysql' => str_contains($queryException->getMessage(), 'Lock wait timeout exceeded'),
            'pgsql' => str_contains($queryException->getMessage(), 'lock timeout'),
            default => false,
        };
    }

    /**
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    private function findAllByState(WorkflowState $workflowState): array
    {
        $models = WorkflowModel::query()
            ->where('state', $workflowState->value)
            ->get();

        return $this->hydrateModels($models->all());
    }

    /**
     * @param array<int|string, WorkflowModel> $models
     *
     * @return list<WorkflowInstance>
     *
     * @throws InvalidDefinitionKeyException
     * @throws InvalidDefinitionVersionException
     * @throws InvalidStepKeyException
     */
    private function hydrateModels(array $models): array
    {
        $result = [];
        foreach ($models as $model) {
            $result[] = $this->workflowHydrator->toDomain($model);
        }

        return $result;
    }
}
