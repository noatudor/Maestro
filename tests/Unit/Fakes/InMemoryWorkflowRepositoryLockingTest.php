<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('InMemoryWorkflowRepository locking', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryWorkflowRepository();
    });

    describe('findAndLockForUpdate', function (): void {
        it('returns the workflow when not locked', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $result = $this->repository->findAndLockForUpdate($workflow->id);

            expect($result->id->value)->toBe($workflow->id->value);
        });

        it('throws WorkflowNotFoundException when workflow does not exist', function (): void {
            $workflowId = WorkflowId::generate();

            expect(fn () => $this->repository->findAndLockForUpdate($workflowId))
                ->toThrow(WorkflowNotFoundException::class);
        });

        it('throws WorkflowLockedException when already locked', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->findAndLockForUpdate($workflow->id);

            expect(fn () => $this->repository->findAndLockForUpdate($workflow->id))
                ->toThrow(WorkflowLockedException::class);
        });

        it('allows lock after release', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $this->repository->findAndLockForUpdate($workflow->id);
            $this->repository->releaseRowLock($workflow->id);

            $result = $this->repository->findAndLockForUpdate($workflow->id);

            expect($result->id->value)->toBe($workflow->id->value);
        });
    });

    describe('withLockedWorkflow', function (): void {
        it('executes callback with locked workflow', function (): void {
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

            $this->repository->findAndLockForUpdate($workflow->id);

            expect(true)->toBeTrue();
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

            $this->repository->findAndLockForUpdate($workflow->id);

            expect(true)->toBeTrue();
        });
    });

    describe('acquireApplicationLock', function (): void {
        it('acquires lock when not locked', function (): void {
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
    });

    describe('releaseApplicationLock', function (): void {
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
            $result = $this->repository->releaseApplicationLock($workflow->id, 'lock-id-2');

            expect($result)->toBeFalse();

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeTrue();
        });
    });

    describe('isLockExpired', function (): void {
        it('returns false when workflow is not locked', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);

            $result = $this->repository->isLockExpired($workflow->id, 30);

            expect($result)->toBeFalse();
        });

        it('returns false when lock is not expired', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);
            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            $result = $this->repository->isLockExpired($workflow->id, 30);

            expect($result)->toBeFalse();
        });
    });

    describe('clearExpiredLocks', function (): void {
        it('clears expired locks', function (): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes(5));

            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);
            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            CarbonImmutable::setTestNow();

            $clearedCount = $this->repository->clearExpiredLocks(60);

            expect($clearedCount)->toBe(1);

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeFalse();
        });

        it('does not clear non-expired locks', function (): void {
            $workflow = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $this->repository->save($workflow);
            $this->repository->acquireApplicationLock($workflow->id, 'lock-id-1');

            $clearedCount = $this->repository->clearExpiredLocks(60);

            expect($clearedCount)->toBe(0);

            $savedWorkflow = $this->repository->find($workflow->id);
            expect($savedWorkflow->isLocked())->toBeTrue();
        });
    });
});
