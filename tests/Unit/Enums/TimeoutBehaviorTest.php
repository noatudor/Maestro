<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\TimeoutBehavior;

describe('TimeoutBehavior', function () {
    it('has expected cases', function () {
        $cases = TimeoutBehavior::cases();

        expect($cases)->toContain(TimeoutBehavior::Fail)
            ->and($cases)->toContain(TimeoutBehavior::Compensate)
            ->and($cases)->toContain(TimeoutBehavior::AwaitDecision);
    });

    it('can create from string value', function () {
        expect(TimeoutBehavior::from('fail'))->toBe(TimeoutBehavior::Fail)
            ->and(TimeoutBehavior::from('compensate'))->toBe(TimeoutBehavior::Compensate)
            ->and(TimeoutBehavior::from('await_decision'))->toBe(TimeoutBehavior::AwaitDecision);
    });

    it('has correct string values', function () {
        expect(TimeoutBehavior::Fail->value)->toBe('fail')
            ->and(TimeoutBehavior::Compensate->value)->toBe('compensate')
            ->and(TimeoutBehavior::AwaitDecision->value)->toBe('await_decision');
    });

    it('returns null for invalid value with tryFrom', function () {
        expect(TimeoutBehavior::tryFrom('invalid'))->toBeNull();
    });

    describe('shouldCompensate', function () {
        it('returns true for Compensate', function () {
            expect(TimeoutBehavior::Compensate->shouldCompensate())->toBeTrue();
        });

        it('returns false for other cases', function () {
            expect(TimeoutBehavior::Fail->shouldCompensate())->toBeFalse()
                ->and(TimeoutBehavior::AwaitDecision->shouldCompensate())->toBeFalse();
        });
    });

    describe('shouldFail', function () {
        it('returns true for Fail', function () {
            expect(TimeoutBehavior::Fail->shouldFail())->toBeTrue();
        });

        it('returns false for other cases', function () {
            expect(TimeoutBehavior::Compensate->shouldFail())->toBeFalse()
                ->and(TimeoutBehavior::AwaitDecision->shouldFail())->toBeFalse();
        });
    });

    describe('shouldAwaitDecision', function () {
        it('returns true for AwaitDecision', function () {
            expect(TimeoutBehavior::AwaitDecision->shouldAwaitDecision())->toBeTrue();
        });

        it('returns false for other cases', function () {
            expect(TimeoutBehavior::Compensate->shouldAwaitDecision())->toBeFalse()
                ->and(TimeoutBehavior::Fail->shouldAwaitDecision())->toBeFalse();
        });
    });
});
