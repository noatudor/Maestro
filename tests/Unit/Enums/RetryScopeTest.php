<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\RetryScope;

describe('RetryScope', static function (): void {
    describe('retriesAllJobs', static function (): void {
        it('returns true for All scope', function (): void {
            expect(RetryScope::All->retriesAllJobs())->toBeTrue();
        });

        it('returns false for FailedOnly scope', function (): void {
            expect(RetryScope::FailedOnly->retriesAllJobs())->toBeFalse();
        });
    });

    describe('retriesFailedJobsOnly', static function (): void {
        it('returns true for FailedOnly scope', function (): void {
            expect(RetryScope::FailedOnly->retriesFailedJobsOnly())->toBeTrue();
        });

        it('returns false for All scope', function (): void {
            expect(RetryScope::All->retriesFailedJobsOnly())->toBeFalse();
        });
    });
});
