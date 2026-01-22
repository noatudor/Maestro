<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\ValueObjects\RetryFromStepRequest;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('RetryFromStepRequest', static function (): void {
    describe('create', static function (): void {
        it('creates request with default values', function (): void {
            $workflowId = WorkflowId::generate();
            $stepKey = StepKey::fromString('step-1');

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowId,
                retryFromStepKey: $stepKey,
            );

            expect($retryFromStepRequest->workflowId)->toBe($workflowId)
                ->and($retryFromStepRequest->retryFromStepKey)->toBe($stepKey)
                ->and($retryFromStepRequest->retryMode)->toBe(RetryMode::RetryOnly)
                ->and($retryFromStepRequest->initiatedBy)->toBeNull()
                ->and($retryFromStepRequest->reason)->toBeNull();
        });

        it('creates request with all values', function (): void {
            $workflowId = WorkflowId::generate();
            $stepKey = StepKey::fromString('step-2');

            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: $workflowId,
                retryFromStepKey: $stepKey,
                retryMode: RetryMode::CompensateThenRetry,
                initiatedBy: 'admin',
                reason: 'Manual retry',
            );

            expect($retryFromStepRequest->workflowId)->toBe($workflowId)
                ->and($retryFromStepRequest->retryFromStepKey)->toBe($stepKey)
                ->and($retryFromStepRequest->retryMode)->toBe(RetryMode::CompensateThenRetry)
                ->and($retryFromStepRequest->initiatedBy)->toBe('admin')
                ->and($retryFromStepRequest->reason)->toBe('Manual retry');
        });
    });

    describe('requiresCompensation', static function (): void {
        it('returns false when retry mode is RetryOnly', function (): void {
            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: WorkflowId::generate(),
                retryFromStepKey: StepKey::fromString('step-1'),
                retryMode: RetryMode::RetryOnly,
            );

            expect($retryFromStepRequest->requiresCompensation())->toBeFalse();
        });

        it('returns true when retry mode is CompensateThenRetry', function (): void {
            $retryFromStepRequest = RetryFromStepRequest::create(
                workflowId: WorkflowId::generate(),
                retryFromStepKey: StepKey::fromString('step-1'),
                retryMode: RetryMode::CompensateThenRetry,
            );

            expect($retryFromStepRequest->requiresCompensation())->toBeTrue();
        });
    });
});
