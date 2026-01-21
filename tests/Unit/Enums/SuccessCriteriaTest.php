<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\SuccessCriteria;

describe('SuccessCriteria', static function (): void {
    describe('requiresAllJobs', static function (): void {
        it('returns true for All criteria', function (): void {
            expect(SuccessCriteria::All->requiresAllJobs())->toBeTrue();
        });

        it('returns false for other criteria', function (SuccessCriteria $successCriteria): void {
            expect($successCriteria->requiresAllJobs())->toBeFalse();
        })->with([
            'Majority' => SuccessCriteria::Majority,
            'BestEffort' => SuccessCriteria::BestEffort,
        ]);
    });

    describe('requiresMajority', static function (): void {
        it('returns true for Majority criteria', function (): void {
            expect(SuccessCriteria::Majority->requiresMajority())->toBeTrue();
        });

        it('returns false for other criteria', function (SuccessCriteria $successCriteria): void {
            expect($successCriteria->requiresMajority())->toBeFalse();
        })->with([
            'All' => SuccessCriteria::All,
            'BestEffort' => SuccessCriteria::BestEffort,
        ]);
    });

    describe('allowsAnySuccess', static function (): void {
        it('returns true for BestEffort criteria', function (): void {
            expect(SuccessCriteria::BestEffort->allowsAnySuccess())->toBeTrue();
        });

        it('returns false for other criteria', function (SuccessCriteria $successCriteria): void {
            expect($successCriteria->allowsAnySuccess())->toBeFalse();
        })->with([
            'All' => SuccessCriteria::All,
            'Majority' => SuccessCriteria::Majority,
        ]);
    });

    describe('evaluate', static function (): void {
        it('returns true for empty total', function (SuccessCriteria $successCriteria): void {
            expect($successCriteria->evaluate(0, 0))->toBeTrue();
        })->with([
            'All' => SuccessCriteria::All,
            'Majority' => SuccessCriteria::Majority,
            'BestEffort' => SuccessCriteria::BestEffort,
        ]);

        describe('All criteria', static function (): void {
            it('returns true when all succeed', function (): void {
                expect(SuccessCriteria::All->evaluate(5, 5))->toBeTrue();
            });

            it('returns false when any fail', function (): void {
                expect(SuccessCriteria::All->evaluate(4, 5))->toBeFalse();
            });
        });

        describe('Majority criteria', static function (): void {
            it('returns true when majority succeed', function (): void {
                expect(SuccessCriteria::Majority->evaluate(3, 5))->toBeTrue();
                expect(SuccessCriteria::Majority->evaluate(2, 3))->toBeTrue();
            });

            it('returns false when less than majority succeed', function (): void {
                expect(SuccessCriteria::Majority->evaluate(2, 5))->toBeFalse();
                expect(SuccessCriteria::Majority->evaluate(1, 3))->toBeFalse();
            });
        });

        describe('BestEffort criteria', static function (): void {
            it('returns true when any succeed', function (): void {
                expect(SuccessCriteria::BestEffort->evaluate(1, 5))->toBeTrue();
            });

            it('returns false when none succeed', function (): void {
                expect(SuccessCriteria::BestEffort->evaluate(0, 5))->toBeFalse();
            });
        });
    });
});
