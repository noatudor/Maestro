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
        CarbonImmutable::setTestNow(null);
    });

    describe('creation', static function (): void {
        it('creates with pending state by default', function (): void {
            $workflow = WorkflowInstance::create(
                $this->definitionKey,
                $this->definitionVersion,
            );

            expect($workflow->state())->toBe(WorkflowState::Pending)
                ->and($workflow->definitionKey)->toBe($this->definitionKey)
                ->and($workflow->definitionVersion)->toBe($this->definitionVersion)
                ->and($workflow->currentStepKey())->toBeNull()
                ->and($workflow->isPending())->toBeTrue();
        });

        it('creates with a provided workflow id', function (): void {
            $id = WorkflowId::generate();
            $workflow = WorkflowInstance::create(
                $this->definitionKey,
                $this->definitionVersion,
                $id,
            );

            expect($workflow->id)->toBe($id);
        });

        it('generates workflow id if not provided', function (): void {
            $workflow = WorkflowInstance::create(
                $this->definitionKey,
                $this->definitionVersion,
            );

            expect($workflow->id->value)->toBeValidUuid();
        });
    });

    describe('state transitions', static function (): void {
        it('transitions from pending to running via start', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');

            $workflow->start($stepKey);

            expect($workflow->state())->toBe(WorkflowState::Running)
                ->and($workflow->currentStepKey())->toBe($stepKey)
                ->and($workflow->isRunning())->toBeTrue();
        });

        it('transitions from running to paused', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);

            $workflow->pause('Waiting for approval');

            expect($workflow->state())->toBe(WorkflowState::Paused)
                ->and($workflow->pausedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflow->pausedReason())->toBe('Waiting for approval')
                ->and($workflow->isPaused())->toBeTrue();
        });

        it('transitions from paused to running via resume', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);
            $workflow->pause('Waiting');

            $workflow->resume();

            expect($workflow->state())->toBe(WorkflowState::Running)
                ->and($workflow->pausedAt())->toBeNull()
                ->and($workflow->pausedReason())->toBeNull();
        });

        it('transitions from running to succeeded', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);

            $workflow->succeed();

            expect($workflow->state())->toBe(WorkflowState::Succeeded)
                ->and($workflow->succeededAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflow->currentStepKey())->toBeNull()
                ->and($workflow->isSucceeded())->toBeTrue()
                ->and($workflow->isTerminal())->toBeTrue();
        });

        it('transitions from running to failed', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);

            $workflow->fail('STEP_FAILED', 'The step failed');

            expect($workflow->state())->toBe(WorkflowState::Failed)
                ->and($workflow->failedAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflow->failureCode())->toBe('STEP_FAILED')
                ->and($workflow->failureMessage())->toBe('The step failed')
                ->and($workflow->isFailed())->toBeTrue()
                ->and($workflow->isTerminal())->toBeTrue();
        });

        it('transitions from paused to cancelled', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);
            $workflow->pause();

            $workflow->cancel();

            expect($workflow->state())->toBe(WorkflowState::Cancelled)
                ->and($workflow->cancelledAt())->toBeInstanceOf(CarbonImmutable::class)
                ->and($workflow->currentStepKey())->toBeNull()
                ->and($workflow->isCancelled())->toBeTrue()
                ->and($workflow->isTerminal())->toBeTrue();
        });

        it('transitions from failed to running via retry', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);
            $workflow->fail('ERROR', 'Something broke');

            $workflow->retry();

            expect($workflow->state())->toBe(WorkflowState::Running)
                ->and($workflow->failedAt())->toBeNull()
                ->and($workflow->failureCode())->toBeNull()
                ->and($workflow->failureMessage())->toBeNull();
        });
    });

    describe('invalid transitions', static function (): void {
        it('throws when starting a non-pending workflow', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);

            expect(fn () => $workflow->start($stepKey))
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when pausing a pending workflow', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);

            expect(fn () => $workflow->pause())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when resuming a non-paused workflow', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);

            expect(fn () => $workflow->resume())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws when cancelling a terminal workflow', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);
            $workflow->succeed();

            expect(fn () => $workflow->cancel())
                ->toThrow(InvalidStateTransitionException::class);
        });

        it('throws WorkflowAlreadyCancelledException when cancelling already cancelled workflow', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);
            $workflow->pause();
            $workflow->cancel();

            expect(fn () => $workflow->cancel())
                ->toThrow(WorkflowAlreadyCancelledException::class);
        });

        it('throws when retrying a non-failed workflow', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);

            expect(fn () => $workflow->retry())
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('step management', static function (): void {
        it('advances to a new step', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $stepKey1 = StepKey::fromString('first-step');
            $stepKey2 = StepKey::fromString('second-step');
            $workflow->start($stepKey1);

            $workflow->advanceToStep($stepKey2);

            expect($workflow->currentStepKey())->toBe($stepKey2);
        });
    });

    describe('locking', static function (): void {
        it('acquires a lock', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $lockId = 'worker-1';

            $workflow->acquireLock($lockId);

            expect($workflow->isLocked())->toBeTrue()
                ->and($workflow->lockedBy())->toBe($lockId)
                ->and($workflow->lockedAt())->toBeInstanceOf(CarbonImmutable::class);
        });

        it('throws when acquiring lock held by another', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $workflow->acquireLock('worker-1');

            expect(fn () => $workflow->acquireLock('worker-2'))
                ->toThrow(WorkflowLockedException::class);
        });

        it('allows reacquiring lock with same id', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $lockId = 'worker-1';
            $workflow->acquireLock($lockId);

            $workflow->acquireLock($lockId);

            expect($workflow->lockedBy())->toBe($lockId);
        });

        it('releases a lock with correct id', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $lockId = 'worker-1';
            $workflow->acquireLock($lockId);

            $released = $workflow->releaseLock($lockId);

            expect($released)->toBeTrue()
                ->and($workflow->isLocked())->toBeFalse()
                ->and($workflow->lockedBy())->toBeNull()
                ->and($workflow->lockedAt())->toBeNull();
        });

        it('does not release a lock with wrong id', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $workflow->acquireLock('worker-1');

            $released = $workflow->releaseLock('worker-2');

            expect($released)->toBeFalse()
                ->and($workflow->isLocked())->toBeTrue();
        });

        it('force releases a lock', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);
            $workflow->acquireLock('worker-1');

            $workflow->forceReleaseLock();

            expect($workflow->isLocked())->toBeFalse();
        });
    });

    describe('state queries', static function (): void {
        it('identifies active states correctly', function (): void {
            $workflow = WorkflowInstance::create($this->definitionKey, $this->definitionVersion);

            expect($workflow->isActive())->toBeTrue();

            $stepKey = StepKey::fromString('first-step');
            $workflow->start($stepKey);
            expect($workflow->isActive())->toBeTrue();

            $workflow->pause();
            expect($workflow->isActive())->toBeTrue();

            $workflow->resume();
            $workflow->succeed();
            expect($workflow->isActive())->toBeFalse();
        });
    });

    describe('reconstitution', static function (): void {
        it('reconstitutes from persisted data', function (): void {
            $id = WorkflowId::generate();
            $now = CarbonImmutable::now();
            $stepKey = StepKey::fromString('current-step');

            $workflow = WorkflowInstance::reconstitute(
                id: $id,
                definitionKey: $this->definitionKey,
                definitionVersion: $this->definitionVersion,
                state: WorkflowState::Running,
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

            expect($workflow->id)->toBe($id)
                ->and($workflow->state())->toBe(WorkflowState::Running)
                ->and($workflow->currentStepKey())->toBe($stepKey)
                ->and($workflow->lockedBy())->toBe('worker-1')
                ->and($workflow->createdAt)->toBe($now);
        });
    });
});
