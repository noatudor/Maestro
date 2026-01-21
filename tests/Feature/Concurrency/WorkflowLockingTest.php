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
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            DB::beginTransaction();

            try {
                $lockedWorkflow = $this->repository->findAndLockForUpdate($workflow->id);

                expect($lockedWorkflow->id->value)->toBe($workflow->id->value);
            } finally {
                DB::rollBack();
            }
        });

        it('throws WorkflowNotFoundException for non-existent workflow', function (): void {
            $nonExistentId = WorkflowId::generate();

            DB::beginTransaction();

            try {
                $this->repository->findAndLockForUpdate($nonExistentId);
            } finally {
                DB::rollBack();
            }
        })->throws(WorkflowNotFoundException::class);
    });

    describe('withLockedWorkflow', function (): void {
        it('executes callback within transaction with row lock', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $callbackExecuted = false;
            $result = $this->repository->withLockedWorkflow(
                $workflow->id,
                function (WorkflowInstance $w) use (&$callbackExecuted): string {
                    $callbackExecuted = true;

                    return 'success';
                },
            );

            expect($callbackExecuted)->toBeTrue();
            expect($result)->toBe('success');
        });

        it('releases lock after callback completes', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->withLockedWorkflow(
                $workflow->id,
                fn (WorkflowInstance $w) => null,
            );

            $secondCallbackExecuted = false;
            $this->repository->withLockedWorkflow(
                $workflow->id,
                function () use (&$secondCallbackExecuted): void {
                    $secondCallbackExecuted = true;
                },
            );

            expect($secondCallbackExecuted)->toBeTrue();
        });

        it('releases lock even when callback throws exception', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            try {
                $this->repository->withLockedWorkflow(
                    $workflow->id,
                    fn (WorkflowInstance $w) => throw new RuntimeException('Test exception'),
                );
            } catch (RuntimeException) {
            }

            $callbackExecutedAfterException = false;
            $this->repository->withLockedWorkflow(
                $workflow->id,
                function () use (&$callbackExecutedAfterException): void {
                    $callbackExecutedAfterException = true;
                },
            );

            expect($callbackExecutedAfterException)->toBeTrue();
        });

        it('persists changes made within callback', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->withLockedWorkflow(
                $workflow->id,
                function (WorkflowInstance $w): void {
                    $w->start(StepKey::fromString('first-step'));
                    $this->repository->save($w);
                },
            );

            $reloaded = $this->repository->findOrFail($workflow->id);
            expect($reloaded->isRunning())->toBeTrue();
        });
    });

    describe('application-level locking', function (): void {
        it('acquires application lock when not locked', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $result = $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            expect($result)->toBeTrue();

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeTrue();
            expect($savedWorkflow->lockedBy())->toBe('lock-id-1');
        });

        it('returns false when already locked by different lock id', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');
            $result = $this->repository->acquireApplicationLock($workflow->id, 'lock-id-2');

            expect($result)->toBeFalse();
        });

        it('releases lock with matching lock id', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');
            $result = $this->repository->releaseApplicationLock($workflow->id, 'lock-id-1');

            expect($result)->toBeTrue();

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeFalse();
        });

        it('does not release lock with different lock id', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');
            $result = $this->repository->releaseApplicationLock($workflow->id, 'wrong-lock-id');

            expect($result)->toBeFalse();

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeTrue();
        });
    });

    describe('lock expiration', function (): void {
        it('detects expired locks', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            DB::table('maestro_workflows')
                ->where('id', $workflow->id->value)
                ->update(['locked_at' => now()->subMinutes(5)]);

            $isExpired = $this->repository->isLockExpired($workflow->id, 60);

            expect($isExpired)->toBeTrue();
        });

        it('returns false for non-expired locks', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            $isExpired = $this->repository->isLockExpired($workflow->id, 300);

            expect($isExpired)->toBeFalse();
        });

        it('clears expired locks', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            DB::table('maestro_workflows')
                ->where('id', $workflow->id->value)
                ->update(['locked_at' => now()->subMinutes(10)]);

            $clearedCount = $this->repository->clearExpiredLocks(60);

            expect($clearedCount)->toBe(1);

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeFalse();
        });
    });
});
