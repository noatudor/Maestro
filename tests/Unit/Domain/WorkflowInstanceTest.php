<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowInstance', static function (): void {
    beforeEach(function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->definitionKey = DefinitionKey::fromString('test-workflow');
        $this->definitionVersion = DefinitionVersion::fromString('1.0.0');
    });

    afterEach(function (): void {
        CarbonImmutable::setTestNow();
    });

    describe('creation', static function (): void {
        it('creates with pending state by default', function (): void {
            $workflowInstance = WorkflowInstance::create(
                $this->definitionKey,
                $this->definitionVersion,
            );

            expect($workflowInstance->state())->toBe(WorkflowState::Pending)
                ->and($workflowInstance->definitionKey)->toBe($this->definitionKey)
                ->and($workflowInstance->definitionVersion)->toBe($this->definitionVersion)
                ->and($workflowInstance->currentStepKey())->toBeNull()
                ->and($workflowInstance->isPending())->toBeTrue();
        });

        it('creates with a provided workflow id', function (): void {
            $workflowId = WorkflowId::generate();
            $workflowInstance = WorkflowInstance::create(
                $this->definitionKey,
                $this->definitionVersion,
                $workflowId,
            );

            expect($workflowInstance->id)->toBe($workflowId);
        });

        it('generates workflow id if not provided', function (): void {
            $workflowInstance = WorkflowInstance::create(
                $this->definitionKey,
                $this->definitionVersion,
            );

            expect($workflowInstance->id->value)->toBeValidUuid();
        });
    });

    describe('state transitions', static function (): void {
        it('transitions from pending to running via start', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');

            $workflowInstance->start($stepKey);

            expect($workflowInstance->state())->toBe(WorkflowState::Running)
                ->and($workflowInstance->currentStepKey())->toBe($stepKey)
                ->and($workflowInstance->isRunning())->toBeTrue();
        });

        it('transitions from running to paused', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);

            $workflowInstance->pause('Waiting for approval');

            expect($workflowInstance->state())->toBe(WorkflowState::Paused)
                ->and($workflowInstance->pausedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflowInstance->pausedReason())->toBe('Waiting for approval')
                ->and($workflowInstance->isPaused())->toBeTrue();
        });

        it('transitions from paused to running via resume', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);
            $workflowInstance->pause('Waiting');

            $workflowInstance->resume();

            expect($workflowInstance->state())->toBe(WorkflowState::Running)
                ->and($workflowInstance->pausedAt())->toBeNull()
                ->and($workflowInstance->pausedReason())->toBeNull();
        });

        it('transitions from running to succeeded', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);

            $workflowInstance->succeed();

            expect($workflowInstance->state())->toBe(WorkflowState::Succeeded)
                ->and($workflowInstance->succeededAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflowInstance->currentStepKey())->toBeNull()
                ->and($workflowInstance->isSucceeded())->toBeTrue()
                ->and($workflowInstance->isTerminal())->toBeTrue();
        });

        it('transitions from running to failed', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);

            $workflowInstance->fail('STEP_FAILED', 'The step failed');

            expect($workflowInstance->state())->toBe(WorkflowState::Failed)
                ->and($workflowInstance->failedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflowInstance->failureCode())->toBe('STEP_FAILED')
                ->and($workflowInstance->failureMessage())->toBe('The step failed')
                ->and($workflowInstance->isFailed())->toBeTrue()
                ->and($workflowInstance->isTerminal())->toBeTrue();
        });

        it('transitions from paused to cancelled', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);
            $workflowInstance->pause();

            $workflowInstance->cancel();

            expect($workflowInstance->state())->toBe(WorkflowState::Cancelled)
                ->and($workflowInstance->cancelledAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflowInstance->currentStepKey())->toBeNull()
                ->and($workflowInstance->isCancelled())->toBeTrue()
                ->and($workflowInstance->isTerminal())->toBeTrue();
        });

        it('transitions from failed to running via retry', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);
            $workflowInstance->fail('ERROR', 'Something broke');

            $workflowInstance->retry();

            expect($workflowInstance->state())->toBe(WorkflowState::Running)
                ->and($workflowInstance->failedAt())->toBeNull()
                ->and($workflowInstance->failureCode())->toBeNull()
                ->and($workflowInstance->failureMessage())->toBeNull();
        });
    });

    describe('invalid transitions', static function (): void {
        it('throws when starting a non-pending workflow', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);

            expect(static fn () => $workflowInstance->start($stepKey))
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when pausing a pending workflow', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);

            expect(static fn () => $workflowInstance->pause())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when resuming a non-paused workflow', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);

            expect(static fn () => $workflowInstance->resume())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when cancelling a terminal workflow', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);
            $workflowInstance->succeed();

            expect(static fn () => $workflowInstance->cancel())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws WorkflowAlreadyCancelledException when cancelling already cancelled workflow', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);
            $workflowInstance->pause();
            $workflowInstance->cancel();

            expect(static fn () => $workflowInstance->cancel())
                ->toThrow(WorkflowAlreadyCancelledException::class);
        });

        it('throws when retrying a non-failed workflow', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);

            expect(static fn () => $workflowInstance->retry())
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('step management', static function (): void {
        it('advances to a new step', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey1 = StepKey::fromString('first-step');
            $stepKey2 = StepKey::fromString('second-step');
            $workflowInstance->start($stepKey1);

            $workflowInstance->advanceToStep($stepKey2);

            expect($workflowInstance->currentStepKey())->toBe($stepKey2);
        });
    });

    describe('locking', static function (): void {
        it('acquires a lock', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $lockId = 'worker-1';

            $workflowInstance->acquireLock($lockId);

            expect($workflowInstance->isLocked())->toBeTrue()
                ->and($workflowInstance->lockedBy())->toBe($lockId)
                ->and($workflowInstance->lockedAt())->toBeInstanceOf(CarbonImmutable::class);
        });

        it('throws when acquiring lock held by another', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $workflowInstance->acquireLock('worker-1');

            expect(static fn () => $workflowInstance->acquireLock('worker-2'))
                ->toThrow(WorkflowLockedException::class);
        });

        it('allows reacquiring lock with same id', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $lockId = 'worker-1';
            $workflowInstance->acquireLock($lockId);

            $workflowInstance->acquireLock($lockId);

            expect($workflowInstance->lockedBy())->toBe($lockId);
        });

        it('releases a lock with correct id', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $lockId = 'worker-1';
            $workflowInstance->acquireLock($lockId);

            $released = $workflowInstance->releaseLock($lockId);

            expect($released)->toBeTrue()
                ->and($workflowInstance->isLocked())->toBeFalse()
                ->and($workflowInstance->lockedBy())->toBeNull()
                ->and($workflowInstance->lockedAt())->toBeNull();
        });

        it('does not release a lock with wrong id', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $workflowInstance->acquireLock('worker-1');

            $released = $workflowInstance->releaseLock('worker-2');

            expect($released)->toBeFalse()
                ->and($workflowInstance->isLocked())->toBeTrue();
        });

        it('force releases a lock', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $workflowInstance->acquireLock('worker-1');

            $workflowInstance->forceReleaseLock();

            expect($workflowInstance->isLocked())->toBeFalse();
        });
    });

    describe('state queries', static function (): void {
        it('identifies active states correctly', function (): void {
            $workflowInstance = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);

            expect($workflowInstance->isActive())->toBeTrue();

            $stepKey = StepKey::fromString('first-step');
            $workflowInstance->start($stepKey);
            expect($workflowInstance->isActive())->toBeTrue();

            $workflowInstance->pause();
            expect($workflowInstance->isActive())->toBeTrue();

            $workflowInstance->resume();
            $workflowInstance->succeed();
            expect($workflowInstance->isActive())->toBeFalse();
        });
    });

    describe('reconstitution', static function (): void {
        it('reconstitutes from persisted data', function (): void {
            $workflowId = WorkflowId::generate();
            $now = CarbonImmutable::now();
            $stepKey = StepKey::fromString('current-step');

            $workflowInstance = WorkflowInstance::reconstitute(
                workflowId: $workflowId,
                definitionKey: $this->definitionKey,
                definitionVersion: $this->definitionVersion,
                workflowState: WorkflowState::Running,
                currentStepKey: $stepKey,
                pausedAt: null,
                pausedReason: null,
                failedAt: null,
                failureCode: null,
                failureMessage: null,
                succeededAt: null,
                cancelledAt: null,
                lockedBy: 'worker-1',
                lockedAt: $now,
                createdAt: $now,
                updatedAt: $now,
            );

            expect($workflowInstance->id)->toBe($workflowId)
                ->and($workflowInstance->state())->toBe(WorkflowState::Running)
                ->and($workflowInstance->currentStepKey())->toBe($stepKey)
                ->and($workflowInstance->lockedBy())->toBe('worker-1')
                ->and($workflowInstance->createdAt)->toBe($now);
        });
    });
});
