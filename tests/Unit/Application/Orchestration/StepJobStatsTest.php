<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\StepJobStats;

describe('StepJobStats', function (): void {
    describe('create', function (): void {
        it('creates with correct values', function (): void {
            $stats = StepJobStats::create(
                total: 10,
                succeeded: 5,
                failed: 2,
                running: 2,
                dispatched: 1,
            );

            expect($stats->total)->toBe(10);
            expect($stats->succeeded)->toBe(5);
            expect($stats->failed)->toBe(2);
            expect($stats->running)->toBe(2);
            expect($stats->dispatched)->toBe(1);
        });

        it('enforces non-negative values', function (): void {
            $stats = StepJobStats::create(
                total: -5,
                succeeded: -3,
                failed: -2,
                running: -1,
                dispatched: -1,
            );

            expect($stats->total)->toBe(0);
            expect($stats->succeeded)->toBe(0);
            expect($stats->failed)->toBe(0);
            expect($stats->running)->toBe(0);
            expect($stats->dispatched)->toBe(0);
        });
    });

    describe('empty', function (): void {
        it('creates empty stats', function (): void {
            $stats = StepJobStats::empty();

            expect($stats->total)->toBe(0);
            expect($stats->succeeded)->toBe(0);
            expect($stats->failed)->toBe(0);
            expect($stats->running)->toBe(0);
            expect($stats->dispatched)->toBe(0);
        });
    });

    describe('completed', function (): void {
        it('returns sum of succeeded and failed', function (): void {
            $stats = StepJobStats::create(10, 5, 3, 1, 1);

            expect($stats->completed())->toBe(8);
        });
    });

    describe('pending', function (): void {
        it('returns sum of running and dispatched', function (): void {
            $stats = StepJobStats::create(10, 5, 3, 1, 1);

            expect($stats->pending())->toBe(2);
        });
    });

    describe('allJobsComplete', function (): void {
        it('returns true when all jobs are complete', function (): void {
            $stats = StepJobStats::create(10, 7, 3, 0, 0);

            expect($stats->allJobsComplete())->toBeTrue();
        });

        it('returns false when some jobs are still running', function (): void {
            $stats = StepJobStats::create(10, 5, 2, 2, 1);

            expect($stats->allJobsComplete())->toBeFalse();
        });

        it('returns true for empty stats', function (): void {
            $stats = StepJobStats::empty();

            expect($stats->allJobsComplete())->toBeTrue();
        });
    });

    describe('hasFailures', function (): void {
        it('returns true when there are failures', function (): void {
            $stats = StepJobStats::create(10, 8, 2, 0, 0);

            expect($stats->hasFailures())->toBeTrue();
        });

        it('returns false when there are no failures', function (): void {
            $stats = StepJobStats::create(10, 10, 0, 0, 0);

            expect($stats->hasFailures())->toBeFalse();
        });
    });

    describe('hasSuccesses', function (): void {
        it('returns true when there are successes', function (): void {
            $stats = StepJobStats::create(10, 5, 5, 0, 0);

            expect($stats->hasSuccesses())->toBeTrue();
        });

        it('returns false when there are no successes', function (): void {
            $stats = StepJobStats::create(10, 0, 5, 5, 0);

            expect($stats->hasSuccesses())->toBeFalse();
        });
    });

    describe('allSucceeded', function (): void {
        it('returns true when all jobs succeeded', function (): void {
            $stats = StepJobStats::create(10, 10, 0, 0, 0);

            expect($stats->allSucceeded())->toBeTrue();
        });

        it('returns false when some jobs failed', function (): void {
            $stats = StepJobStats::create(10, 9, 1, 0, 0);

            expect($stats->allSucceeded())->toBeFalse();
        });

        it('returns false for empty stats', function (): void {
            $stats = StepJobStats::empty();

            expect($stats->allSucceeded())->toBeFalse();
        });
    });

    describe('allFailed', function (): void {
        it('returns true when all jobs failed', function (): void {
            $stats = StepJobStats::create(10, 0, 10, 0, 0);

            expect($stats->allFailed())->toBeTrue();
        });

        it('returns false when some jobs succeeded', function (): void {
            $stats = StepJobStats::create(10, 1, 9, 0, 0);

            expect($stats->allFailed())->toBeFalse();
        });
    });

    describe('successRate', function (): void {
        it('calculates correct success rate', function (): void {
            $stats = StepJobStats::create(10, 7, 3, 0, 0);

            expect($stats->successRate())->toBe(0.7);
        });

        it('returns 1.0 for empty stats', function (): void {
            $stats = StepJobStats::empty();

            expect($stats->successRate())->toBe(1.0);
        });

        it('returns 0.0 when all failed', function (): void {
            $stats = StepJobStats::create(10, 0, 10, 0, 0);

            expect($stats->successRate())->toBe(0.0);
        });
    });
});
