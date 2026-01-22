<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\ValueObjects\ConditionResult;

describe('ConditionResult', static function (): void {
    describe('execute', static function (): void {
        it('creates a result indicating execution', function (): void {
            $conditionResult = ConditionResult::execute();

            expect($conditionResult->shouldExecute())->toBeTrue();
            expect($conditionResult->shouldSkip())->toBeFalse();
            expect($conditionResult->skipReason())->toBeNull();
            expect($conditionResult->skipMessage())->toBeNull();
        });
    });

    describe('skip', static function (): void {
        it('creates a result indicating skip with reason', function (): void {
            $conditionResult = ConditionResult::skip(SkipReason::ConditionFalse);

            expect($conditionResult->shouldExecute())->toBeFalse();
            expect($conditionResult->shouldSkip())->toBeTrue();
            expect($conditionResult->skipReason())->toBe(SkipReason::ConditionFalse);
            expect($conditionResult->skipMessage())->toBeNull();
        });

        it('creates a result with skip reason and message', function (): void {
            $conditionResult = ConditionResult::skip(
                SkipReason::NotOnActiveBranch,
                'Step is not on the selected branch',
            );

            expect($conditionResult->shouldExecute())->toBeFalse();
            expect($conditionResult->shouldSkip())->toBeTrue();
            expect($conditionResult->skipReason())->toBe(SkipReason::NotOnActiveBranch);
            expect($conditionResult->skipMessage())->toBe('Step is not on the selected branch');
        });

        it('can skip with TerminatedEarly reason', function (): void {
            $conditionResult = ConditionResult::skip(SkipReason::TerminatedEarly, 'Workflow ended');

            expect($conditionResult->skipReason())->toBe(SkipReason::TerminatedEarly);
            expect($conditionResult->skipMessage())->toBe('Workflow ended');
        });
    });

    describe('shouldExecute', static function (): void {
        it('returns true only for execute results', function (): void {
            $conditionResult = ConditionResult::execute();
            $skipResult = ConditionResult::skip(SkipReason::ConditionFalse);

            expect($conditionResult->shouldExecute())->toBeTrue();
            expect($skipResult->shouldExecute())->toBeFalse();
        });
    });

    describe('shouldSkip', static function (): void {
        it('returns true only for skip results', function (): void {
            $conditionResult = ConditionResult::execute();
            $skipResult = ConditionResult::skip(SkipReason::ConditionFalse);

            expect($conditionResult->shouldSkip())->toBeFalse();
            expect($skipResult->shouldSkip())->toBeTrue();
        });
    });

    it('is readonly', function (): void {
        expect(ConditionResult::class)->toBeImmutable();
    });
});
