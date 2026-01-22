<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('WorkflowInstance Trigger State', function (): void {
    beforeEach(function (): void {
        $this->workflow = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
        );
    });

    describe('pauseForTrigger()', function (): void {
        it('pauses workflow and sets trigger state', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));

            $timeoutAt = CarbonImmutable::now()->addHours(24);
            $this->workflow->pauseForTrigger('approval', $timeoutAt);

            expect($this->workflow->state())->toBe(WorkflowState::Paused)
                ->and($this->workflow->awaitingTriggerKey())->toBe('approval')
                ->and($this->workflow->triggerTimeoutAt())->not->toBeNull()
                ->and($this->workflow->triggerRegisteredAt())->not->toBeNull()
                ->and($this->workflow->isAwaitingTrigger())->toBeTrue()
                ->and($this->workflow->isAwaitingTriggerKey('approval'))->toBeTrue()
                ->and($this->workflow->isAwaitingTriggerKey('other'))->toBeFalse();
        });

        it('sets scheduled resume time when provided', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));

            $timeoutAt = CarbonImmutable::now()->addHours(24);
            $scheduledResumeAt = CarbonImmutable::now()->addHours(2);
            $this->workflow->pauseForTrigger('cooling-off', $timeoutAt, $scheduledResumeAt);

            expect($this->workflow->scheduledResumeAt())->not->toBeNull();
        });

        it('sets custom pause reason when provided', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));

            $timeoutAt = CarbonImmutable::now()->addHours(24);
            $this->workflow->pauseForTrigger('approval', $timeoutAt, null, 'Awaiting manager approval');

            expect($this->workflow->pausedReason())->toBe('Awaiting manager approval');
        });

        it('throws when workflow is pending', function (): void {
            $timeoutAt = CarbonImmutable::now()->addHours(24);

            expect(fn () => $this->workflow->pauseForTrigger('approval', $timeoutAt))
                ->toThrow(InvalidStateTransitionException::class);
        });
    });

    describe('resumeFromTrigger()', function (): void {
        it('resumes workflow and clears trigger state', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->pauseForTrigger('approval', CarbonImmutable::now()->addHours(24));

            $this->workflow->resumeFromTrigger();

            expect($this->workflow->state())->toBe(WorkflowState::Running)
                ->and($this->workflow->awaitingTriggerKey())->toBeNull()
                ->and($this->workflow->triggerTimeoutAt())->toBeNull()
                ->and($this->workflow->triggerRegisteredAt())->toBeNull()
                ->and($this->workflow->scheduledResumeAt())->toBeNull()
                ->and($this->workflow->pausedAt())->toBeNull()
                ->and($this->workflow->pausedReason())->toBeNull();
        });
    });

    describe('resume()', function (): void {
        it('clears trigger state when resuming normally', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->pauseForTrigger('approval', CarbonImmutable::now()->addHours(24));

            $this->workflow->resume();

            expect($this->workflow->awaitingTriggerKey())->toBeNull()
                ->and($this->workflow->triggerTimeoutAt())->toBeNull();
        });
    });

    describe('isTriggerTimedOut()', function (): void {
        it('returns false when no timeout is set', function (): void {
            expect($this->workflow->isTriggerTimedOut())->toBeFalse();
        });

        it('returns false when timeout is in the future', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->pauseForTrigger('approval', CarbonImmutable::now()->addHours(24));

            expect($this->workflow->isTriggerTimedOut())->toBeFalse();
        });

        it('returns true when timeout is in the past', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->pauseForTrigger('approval', CarbonImmutable::now()->subMinute());

            expect($this->workflow->isTriggerTimedOut())->toBeTrue();
        });
    });

    describe('isScheduledResumeDue()', function (): void {
        it('returns false when no scheduled resume is set', function (): void {
            expect($this->workflow->isScheduledResumeDue())->toBeFalse();
        });

        it('returns false when scheduled resume is in the future', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->pauseForTrigger(
                'cooling-off',
                CarbonImmutable::now()->addHours(24),
                CarbonImmutable::now()->addHours(2),
            );

            expect($this->workflow->isScheduledResumeDue())->toBeFalse();
        });

        it('returns true when scheduled resume is in the past', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->pauseForTrigger(
                'cooling-off',
                CarbonImmutable::now()->addHours(24),
                CarbonImmutable::now()->subMinute(),
            );

            expect($this->workflow->isScheduledResumeDue())->toBeTrue();
        });
    });
});
