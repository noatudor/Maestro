<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;

describe('PauseTriggerDefinition', static function (): void {
    describe('create()', static function (): void {
        it('creates a pause trigger with defaults', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval');

            expect($pauseTriggerDefinition->triggerKey)->toBe('approval')
                ->and($pauseTriggerDefinition->timeoutSeconds)->toBe(604800)
                ->and($pauseTriggerDefinition->timeoutPolicy)->toBe(TriggerTimeoutPolicy::FailWorkflow)
                ->and($pauseTriggerDefinition->scheduledResumeSeconds)->toBeNull()
                ->and($pauseTriggerDefinition->resumeConditionClass)->toBeNull()
                ->and($pauseTriggerDefinition->payloadOutputClass)->toBeNull();
        });

        it('creates a pause trigger with custom timeout', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create(
                triggerKey: 'manual-review',
                timeoutSeconds: 3600,
                triggerTimeoutPolicy: TriggerTimeoutPolicy::SendReminder,
            );

            expect($pauseTriggerDefinition->timeoutSeconds)->toBe(3600)
                ->and($pauseTriggerDefinition->timeoutPolicy)->toBe(TriggerTimeoutPolicy::SendReminder);
        });

        it('enforces minimum timeout of 60 seconds', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create(
                triggerKey: 'test',
                timeoutSeconds: 10,
            );

            expect($pauseTriggerDefinition->timeoutSeconds)->toBe(60);
        });
    });

    describe('scheduledResume()', static function (): void {
        it('creates a scheduled resume trigger', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::scheduledResume(
                triggerKey: 'cooling-off',
                resumeAfterSeconds: 7200,
            );

            expect($pauseTriggerDefinition->triggerKey)->toBe('cooling-off')
                ->and($pauseTriggerDefinition->scheduledResumeSeconds)->toBe(7200)
                ->and($pauseTriggerDefinition->timeoutPolicy)->toBe(TriggerTimeoutPolicy::AutoResume)
                ->and($pauseTriggerDefinition->hasScheduledResume())->toBeTrue()
                ->and($pauseTriggerDefinition->expectsExternalTrigger())->toBeFalse();
        });
    });

    describe('hasResumeCondition()', static function (): void {
        it('returns false when no resume condition is set', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval');

            expect($pauseTriggerDefinition->hasResumeCondition())->toBeFalse();
        });

        it('returns true when resume condition is set', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create(
                triggerKey: 'approval',
                resumeConditionClass: 'App\\Conditions\\ValidApproval',
            );

            expect($pauseTriggerDefinition->hasResumeCondition())->toBeTrue();
        });
    });

    describe('hasPayloadOutput()', static function (): void {
        it('returns false when no payload output is set', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval');

            expect($pauseTriggerDefinition->hasPayloadOutput())->toBeFalse();
        });

        it('returns true when payload output is set', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create(
                triggerKey: 'approval',
                payloadOutputClass: 'App\\Outputs\\ApprovalOutput',
            );

            expect($pauseTriggerDefinition->hasPayloadOutput())->toBeTrue();
        });
    });

    describe('hasReminders()', static function (): void {
        it('returns false when policy is not SendReminder', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create(
                triggerKey: 'approval',
                triggerTimeoutPolicy: TriggerTimeoutPolicy::FailWorkflow,
                reminderIntervalSeconds: 3600,
            );

            expect($pauseTriggerDefinition->hasReminders())->toBeFalse();
        });

        it('returns true when policy is SendReminder with interval', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create(
                triggerKey: 'approval',
                triggerTimeoutPolicy: TriggerTimeoutPolicy::SendReminder,
                reminderIntervalSeconds: 3600,
            );

            expect($pauseTriggerDefinition->hasReminders())->toBeTrue();
        });
    });

    describe('with* methods', static function (): void {
        it('creates new instance with modified timeout', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval');
            $modified = $pauseTriggerDefinition->withTimeout(7200);

            expect($modified->timeoutSeconds)->toBe(7200)
                ->and($pauseTriggerDefinition->timeoutSeconds)->toBe(604800);
        });

        it('creates new instance with modified timeout policy', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval');
            $modified = $pauseTriggerDefinition->withTimeoutPolicy(TriggerTimeoutPolicy::ExtendTimeout);

            expect($modified->timeoutPolicy)->toBe(TriggerTimeoutPolicy::ExtendTimeout)
                ->and($pauseTriggerDefinition->timeoutPolicy)->toBe(TriggerTimeoutPolicy::FailWorkflow);
        });

        it('creates new instance with resume condition', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval');
            $modified = $pauseTriggerDefinition->withResumeCondition('App\\Conditions\\ValidApproval');

            expect($modified->resumeConditionClass)->toBe('App\\Conditions\\ValidApproval')
                ->and($pauseTriggerDefinition->resumeConditionClass)->toBeNull();
        });
    });

    describe('equals()', static function (): void {
        it('returns true for identical triggers', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval', 3600);
            $trigger2 = PauseTriggerDefinition::create('approval', 3600);

            expect($pauseTriggerDefinition->equals($trigger2))->toBeTrue();
        });

        it('returns false for different triggers', function (): void {
            $pauseTriggerDefinition = PauseTriggerDefinition::create('approval', 3600);
            $trigger2 = PauseTriggerDefinition::create('review', 3600);

            expect($pauseTriggerDefinition->equals($trigger2))->toBeFalse();
        });
    });
});
