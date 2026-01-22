<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\SkipReason;

describe('SkipReason', static function (): void {
    describe('displayName', static function (): void {
        it('returns correct display name for ConditionFalse', function (): void {
            expect(SkipReason::ConditionFalse->displayName())->toBe('Condition evaluated to false');
        });

        it('returns correct display name for NotOnActiveBranch', function (): void {
            expect(SkipReason::NotOnActiveBranch->displayName())->toBe('Not on active branch');
        });

        it('returns correct display name for TerminatedEarly', function (): void {
            expect(SkipReason::TerminatedEarly->displayName())->toBe('Workflow terminated early');
        });
    });

    describe('backing values', static function (): void {
        it('has correct backing values', function (): void {
            expect(SkipReason::ConditionFalse->value)->toBe('condition_false');
            expect(SkipReason::NotOnActiveBranch->value)->toBe('not_on_active_branch');
            expect(SkipReason::TerminatedEarly->value)->toBe('terminated_early');
        });
    });

    describe('from backing value', static function (): void {
        it('creates from backing value', function (): void {
            expect(SkipReason::from('condition_false'))->toBe(SkipReason::ConditionFalse);
            expect(SkipReason::from('not_on_active_branch'))->toBe(SkipReason::NotOnActiveBranch);
            expect(SkipReason::from('terminated_early'))->toBe(SkipReason::TerminatedEarly);
        });
    });
});
