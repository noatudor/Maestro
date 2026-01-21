<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\StepJobStats;

describe('StepJobStats', static function (): void {
    describe('create', static function (): void {
        it('creates with correct values', function (): void {
            $stepJobStats = StepJobStats::create(
                total: 10,
                succeeded: 5,
                failed: 2,
                running: 2,
                dispatched: 1,
            );

            expect($stepJobStats->total)->toBe(10);
            expect($stepJobStats->succeeded)->toBe(5);
            expect($stepJobStats->failed)->toBe(2);
            expect($stepJobStats->running)->toBe(2);
            expect($stepJobStats->dispatched)->toBe(1);
        });

        it('enforces non-negative values', function (): void {
            $stepJobStats = StepJobStats::create(
                total: -5,
                succeeded: -3,
                failed: -2,
                running: -1,
                dispatched: -1,
            );

            expect($stepJobStats->total)->toBe(0);
            expect($stepJobStats->succeeded)->toBe(0);
            expect($stepJobStats->failed)->toBe(0);
            expect($stepJobStats->running)->toBe(0);
            expect($stepJobStats->dispatched)->toBe(0);
        });
    });

    describe('empty', static function (): void {
        it('creates empty stats', function (): void {
            $stepJobStats = StepJobStats::empty();

            expect($stepJobStats->total)->toBe(0);
            expect($stepJobStats->succeeded)->toBe(0);
            expect($stepJobStats->failed)->toBe(0);
            expect($stepJobStats->running)->toBe(0);
            expect($stepJobStats->dispatched)->toBe(0);
        });
    });

    describe('completed', static function (): void {
        it('returns sum of succeeded and failed', function (): void {
            $stepJobStats = StepJobStats::create(10, 5, 3, 1, 1);

            expect($stepJobStats->completed())->toBe(8);
        });
    });

    describe('pending', static function (): void {
        it('returns sum of running and dispatched', function (): void {
            $stepJobStats = StepJobStats::create(10, 5, 3, 1, 1);

            expect($stepJobStats->pending())->toBe(2);
        });
    });

    describe('allJobsComplete', static function (): void {
        it('returns true when all jobs are complete', function (): void {
            $stepJobStats = StepJobStats::create(10, 7, 3, 0, 0);

            expect($stepJobStats->allJobsComplete())->toBeTrue();
        });

        it('returns false when some jobs are still running', function (): void {
            $stepJobStats = StepJobStats::create(10, 5, 2, 2, 1);

            expect($stepJobStats->allJobsComplete())->toBeFalse();
        });

        it('returns true for empty stats', function (): void {
            $stepJobStats = StepJobStats::empty();

            expect($stepJobStats->allJobsComplete())->toBeTrue();
        });
    });

    describe('hasFailures', static function (): void {
        it('returns true when there are failures', function (): void {
            $stepJobStats = StepJobStats::create(10, 8, 2, 0, 0);

            expect($stepJobStats->hasFailures())->toBeTrue();
        });

        it('returns false when there are no failures', function (): void {
            $stepJobStats = StepJobStats::create(10, 10, 0, 0, 0);

            expect($stepJobStats->hasFailures())->toBeFalse();
        });
    });

    describe('hasSuccesses', static function (): void {
        it('returns true when there are successes', function (): void {
            $stepJobStats = StepJobStats::create(10, 5, 5, 0, 0);

            expect($stepJobStats->hasSuccesses())->toBeTrue();
        });

        it('returns false when there are no successes', function (): void {
            $stepJobStats = StepJobStats::create(10, 0, 5, 5, 0);

            expect($stepJobStats->hasSuccesses())->toBeFalse();
        });
    });

    describe('allSucceeded', static function (): void {
        it('returns true when all jobs succeeded', function (): void {
            $stepJobStats = StepJobStats::create(10, 10, 0, 0, 0);

            expect($stepJobStats->allSucceeded())->toBeTrue();
        });

        it('returns false when some jobs failed', function (): void {
            $stepJobStats = StepJobStats::create(10, 9, 1, 0, 0);

            expect($stepJobStats->allSucceeded())->toBeFalse();
        });

        it('returns false for empty stats', function (): void {
            $stepJobStats = StepJobStats::empty();

            expect($stepJobStats->allSucceeded())->toBeFalse();
        });
    });

    describe('allFailed', static function (): void {
        it('returns true when all jobs failed', function (): void {
            $stepJobStats = StepJobStats::create(10, 0, 10, 0, 0);

            expect($stepJobStats->allFailed())->toBeTrue();
        });

        it('returns false when some jobs succeeded', function (): void {
            $stepJobStats = StepJobStats::create(10, 1, 9, 0, 0);

            expect($stepJobStats->allFailed())->toBeFalse();
        });
    });

    describe('successRate', static function (): void {
        it('calculates correct success rate', function (): void {
            $stepJobStats = StepJobStats::create(10, 7, 3, 0, 0);

            expect($stepJobStats->successRate())->toBe(0.7);
        });

        it('returns 1.0 for empty stats', function (): void {
            $stepJobStats = StepJobStats::empty();

            expect($stepJobStats->successRate())->toBe(1.0);
        });

        it('returns 0.0 when all failed', function (): void {
            $stepJobStats = StepJobStats::create(10, 0, 10, 0, 0);

            expect($stepJobStats->successRate())->toBe(0.0);
        });
    });
});
