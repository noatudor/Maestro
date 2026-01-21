<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\WorkflowHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('Workflow locking with real database', function (): void {
    beforeEach(function (): void {
        $this->hydrator = new WorkflowHydrator();
        $this->repository = new EloquentWorkflowRepository(
            $this->hydrator,
            DB::connection(),
        );
    });

    describe('findAndLockForUpdate', function (): void {
        it('acquires SELECT FOR UPDATE lock on workflow row', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            DB::beginTransaction();

            try {
                $lockedWorkflow = $this->repository->findAndLockForUpdate($workflowInstance->id);

                expect($lockedWorkflow->id->value)->toBe($workflowInstance->id->value);
            } finally {
                DB::rollBack();
            }
        });

        it('throws WorkflowNotFoundException for non-existent workflow', function (): void {
            $workflowId = WorkflowId::generate();

            DB::beginTransaction();

            try {
                $this->repository->findAndLockForUpdate($workflowId);
            } finally {
                DB::rollBack();
            }
        })->throws(WorkflowNotFoundException::class);
    });

    describe('withLockedWorkflow', function (): void {
        it('executes callback within transaction with row lock', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $callbackExecuted = false;
            $result = $this->repository->withLockedWorkflow(
                $workflowInstance->id,
                static function (WorkflowInstance $workflowInstance) use (&$callbackExecuted): string {
                    $callbackExecuted = true;

                    return 'success';
                },
            );

            expect($callbackExecuted)->toBeTrue();
            expect($result)->toBe('success');
        });

        it('releases lock after callback completes', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->withLockedWorkflow(
                $workflowInstance->id,
                static fn (WorkflowInstance $workflowInstance): null => null,
            );

            $secondCallbackExecuted = false;
            $this->repository->withLockedWorkflow(
                $workflowInstance->id,
                static function () use (&$secondCallbackExecuted): void {
                    $secondCallbackExecuted = true;
                },
            );

            expect($secondCallbackExecuted)->toBeTrue();
        });

        it('releases lock even when callback throws exception', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            try {
                $this->repository->withLockedWorkflow(
                    $workflowInstance->id,
                    static fn (WorkflowInstance $workflowInstance) => throw new RuntimeException('Test exception'),
                );
            } catch (RuntimeException) {
            }

            $callbackExecutedAfterException = false;
            $this->repository->withLockedWorkflow(
                $workflowInstance->id,
                static function () use (&$callbackExecutedAfterException): void {
                    $callbackExecutedAfterException = true;
                },
            );

            expect($callbackExecutedAfterException)->toBeTrue();
        });

        it('persists changes made within callback', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->withLockedWorkflow(
                $workflowInstance->id,
                function (WorkflowInstance $workflowInstance): void {
                    $workflowInstance->start(StepKey::fromString('first-step'));
                    $this->repository->save($workflowInstance);
                },
            );

            $reloaded = $this->repository->findOrFail($workflowInstance->id);
            expect($reloaded->isRunning())->toBeTrue();
        });
    });

    describe('application-level locking', function (): void {
        it('acquires application lock when not locked', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $result = $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');

            expect($result)->toBeTrue();

            $savedWorkflow = $this->repository->find($workflowInstance->id);
            expect($savedWorkflow->isLocked())->toBeTrue();
            expect($savedWorkflow->lockedBy())->toBe('lock-id-1');
        });

        it('returns false when already locked by different lock id', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');
            $result = $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-2');

            expect($result)->toBeFalse();
        });

        it('releases lock with matching lock id', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');
            $result = $this->repository->releaseApplicationLock($workflowInstance->id, 'lock-id-1');

            expect($result)->toBeTrue();

            $savedWorkflow = $this->repository->find($workflowInstance->id);
            expect($savedWorkflow->isLocked())->toBeFalse();
        });

        it('does not release lock with different lock id', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');
            $result = $this->repository->releaseApplicationLock($workflowInstance->id, 'wrong-lock-id');

            expect($result)->toBeFalse();

            $savedWorkflow = $this->repository->find($workflowInstance->id);
            expect($savedWorkflow->isLocked())->toBeTrue();
        });
    });

    describe('lock expiration', function (): void {
        it('detects expired locks', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');

            DB::table('maestro_workflows')
                ->where('id', $workflowInstance->id->value)
                ->update(['locked_at' => now()->subMinutes(5)]);

            $isExpired = $this->repository->isLockExpired($workflowInstance->id, 60);

            expect($isExpired)->toBeTrue();
        });

        it('returns false for non-expired locks', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');

            $isExpired = $this->repository->isLockExpired($workflowInstance->id, 300);

            expect($isExpired)->toBeFalse();
        });

        it('clears expired locks', function (): void {
            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflowInstance);

            $this->repository->acquireApplicationLock($workflowInstance->id, 'lock-id-1');

            DB::table('maestro_workflows')
                ->where('id', $workflowInstance->id->value)
                ->update(['locked_at' => now()->subMinutes(10)]);

            $clearedCount = $this->repository->clearExpiredLocks(60);

            expect($clearedCount)->toBe(1);

            $savedWorkflow = $this->repository->find($workflowInstance->id);
            expect($savedWorkflow->isLocked())->toBeFalse();
        });
    });
});
