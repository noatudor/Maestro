<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\ResumeConditionResult;

describe('ResumeConditionResult', static function (): void {
    describe('resume()', static function (): void {
        it('creates a result indicating resume should happen', function (): void {
            $resumeConditionResult = ResumeConditionResult::resume();

            expect($resumeConditionResult->shouldResume())->toBeTrue()
                ->and($resumeConditionResult->rejectionReason())->toBeNull();
        });
    });

    describe('reject()', static function (): void {
        it('creates a result indicating resume should not happen', function (): void {
            $resumeConditionResult = ResumeConditionResult::reject('Payload validation failed');

            expect($resumeConditionResult->shouldResume())->toBeFalse()
                ->and($resumeConditionResult->rejectionReason())->toBe('Payload validation failed');
        });
    });
});
