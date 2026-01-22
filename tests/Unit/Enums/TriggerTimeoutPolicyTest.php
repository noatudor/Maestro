<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\TriggerTimeoutPolicy;

describe('TriggerTimeoutPolicy', static function (): void {
    describe('shouldFailWorkflow()', static function (): void {
        it('returns true only for FailWorkflow', function (): void {
            expect(TriggerTimeoutPolicy::FailWorkflow->shouldFailWorkflow())->toBeTrue()
                ->and(TriggerTimeoutPolicy::SendReminder->shouldFailWorkflow())->toBeFalse()
                ->and(TriggerTimeoutPolicy::AutoResume->shouldFailWorkflow())->toBeFalse()
                ->and(TriggerTimeoutPolicy::ExtendTimeout->shouldFailWorkflow())->toBeFalse();
        });
    });

    describe('shouldSendReminder()', static function (): void {
        it('returns true only for SendReminder', function (): void {
            expect(TriggerTimeoutPolicy::SendReminder->shouldSendReminder())->toBeTrue()
                ->and(TriggerTimeoutPolicy::FailWorkflow->shouldSendReminder())->toBeFalse()
                ->and(TriggerTimeoutPolicy::AutoResume->shouldSendReminder())->toBeFalse()
                ->and(TriggerTimeoutPolicy::ExtendTimeout->shouldSendReminder())->toBeFalse();
        });
    });

    describe('shouldAutoResume()', static function (): void {
        it('returns true only for AutoResume', function (): void {
            expect(TriggerTimeoutPolicy::AutoResume->shouldAutoResume())->toBeTrue()
                ->and(TriggerTimeoutPolicy::FailWorkflow->shouldAutoResume())->toBeFalse()
                ->and(TriggerTimeoutPolicy::SendReminder->shouldAutoResume())->toBeFalse()
                ->and(TriggerTimeoutPolicy::ExtendTimeout->shouldAutoResume())->toBeFalse();
        });
    });

    describe('shouldExtendTimeout()', static function (): void {
        it('returns true only for ExtendTimeout', function (): void {
            expect(TriggerTimeoutPolicy::ExtendTimeout->shouldExtendTimeout())->toBeTrue()
                ->and(TriggerTimeoutPolicy::FailWorkflow->shouldExtendTimeout())->toBeFalse()
                ->and(TriggerTimeoutPolicy::SendReminder->shouldExtendTimeout())->toBeFalse()
                ->and(TriggerTimeoutPolicy::AutoResume->shouldExtendTimeout())->toBeFalse();
        });
    });

    describe('backed values', static function (): void {
        it('has correct string values', function (): void {
            expect(TriggerTimeoutPolicy::FailWorkflow->value)->toBe('fail_workflow')
                ->and(TriggerTimeoutPolicy::SendReminder->value)->toBe('send_reminder')
                ->and(TriggerTimeoutPolicy::AutoResume->value)->toBe('auto_resume')
                ->and(TriggerTimeoutPolicy::ExtendTimeout->value)->toBe('extend_timeout');
        });

        it('can be constructed from string values', function (): void {
            expect(TriggerTimeoutPolicy::from('fail_workflow'))->toBe(TriggerTimeoutPolicy::FailWorkflow)
                ->and(TriggerTimeoutPolicy::from('send_reminder'))->toBe(TriggerTimeoutPolicy::SendReminder)
                ->and(TriggerTimeoutPolicy::from('auto_resume'))->toBe(TriggerTimeoutPolicy::AutoResume)
                ->and(TriggerTimeoutPolicy::from('extend_timeout'))->toBe(TriggerTimeoutPolicy::ExtendTimeout);
        });
    });
});
