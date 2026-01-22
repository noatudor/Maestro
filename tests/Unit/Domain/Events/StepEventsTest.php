<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Events\StepFailed;
use Maestro\Workflow\Domain\Events\StepRetried;
use Maestro\Workflow\Domain\Events\StepStarted;
use Maestro\Workflow\Domain\Events\StepSucceeded;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepStarted', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $stepKey = StepKey::fromString('process-data');
        $attempt = 1;
        $occurredAt = CarbonImmutable::now();

        $event = new StepStarted(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            stepKey: $stepKey,
            attempt: $attempt,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->stepKey)->toBe($stepKey);
        expect($event->attempt)->toBe($attempt);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(StepStarted::class)->toBeImmutable();
    });
});

describe('StepSucceeded', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $stepKey = StepKey::fromString('process-data');
        $attempt = 2;
        $totalJobCount = 10;
        $durationMs = 1500;
        $occurredAt = CarbonImmutable::now();

        $event = new StepSucceeded(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            stepKey: $stepKey,
            attempt: $attempt,
            totalJobCount: $totalJobCount,
            durationMs: $durationMs,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->stepKey)->toBe($stepKey);
        expect($event->attempt)->toBe($attempt);
        expect($event->totalJobCount)->toBe($totalJobCount);
        expect($event->durationMs)->toBe($durationMs);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null duration', function (): void {
        $event = new StepSucceeded(
            workflowId: WorkflowId::generate(),
            stepRunId: StepRunId::generate(),
            stepKey: StepKey::fromString('process-data'),
            attempt: 1,
            totalJobCount: 5,
            durationMs: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->durationMs)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(StepSucceeded::class)->toBeImmutable();
    });
});

describe('StepFailed', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $stepKey = StepKey::fromString('process-data');
        $attempt = 3;
        $failedJobCount = 2;
        $totalJobCount = 10;
        $failureCode = 'JOB_ERROR';
        $failureMessage = 'Jobs failed during processing';
        $durationMs = 2000;
        $occurredAt = CarbonImmutable::now();

        $event = new StepFailed(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            stepKey: $stepKey,
            attempt: $attempt,
            failedJobCount: $failedJobCount,
            totalJobCount: $totalJobCount,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            durationMs: $durationMs,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->stepKey)->toBe($stepKey);
        expect($event->attempt)->toBe($attempt);
        expect($event->failedJobCount)->toBe($failedJobCount);
        expect($event->totalJobCount)->toBe($totalJobCount);
        expect($event->failureCode)->toBe($failureCode);
        expect($event->failureMessage)->toBe($failureMessage);
        expect($event->durationMs)->toBe($durationMs);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null failure details', function (): void {
        $event = new StepFailed(
            workflowId: WorkflowId::generate(),
            stepRunId: StepRunId::generate(),
            stepKey: StepKey::fromString('process-data'),
            attempt: 1,
            failedJobCount: 3,
            totalJobCount: 5,
            failureCode: null,
            failureMessage: null,
            durationMs: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->failureCode)->toBeNull();
        expect($event->failureMessage)->toBeNull();
        expect($event->durationMs)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(StepFailed::class)->toBeImmutable();
    });
});

describe('StepRetried', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $stepKey = StepKey::fromString('process-data');
        $attempt = 2;
        $previousStepRunId = StepRunId::generate();
        $previousAttempt = 1;
        $occurredAt = CarbonImmutable::now();

        $event = new StepRetried(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            stepKey: $stepKey,
            attempt: $attempt,
            previousStepRunId: $previousStepRunId,
            previousAttempt: $previousAttempt,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->stepKey)->toBe($stepKey);
        expect($event->attempt)->toBe($attempt);
        expect($event->previousStepRunId)->toBe($previousStepRunId);
        expect($event->previousAttempt)->toBe($previousAttempt);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(StepRetried::class)->toBeImmutable();
    });
});
