<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Events\JobDispatched;
use Maestro\Workflow\Domain\Events\JobFailed;
use Maestro\Workflow\Domain\Events\JobStarted;
use Maestro\Workflow\Domain\Events\JobSucceeded;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobDispatched', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $jobId = JobId::generate();
        $jobUuid = 'job-uuid-123';
        $jobClass = 'App\\Jobs\\ProcessDataJob';
        $queue = 'high-priority';
        $occurredAt = CarbonImmutable::now();

        $event = new JobDispatched(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobClass: $jobClass,
            queue: $queue,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->jobId)->toBe($jobId);
        expect($event->jobUuid)->toBe($jobUuid);
        expect($event->jobClass)->toBe($jobClass);
        expect($event->queue)->toBe($queue);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(JobDispatched::class)->toBeImmutable();
    });
});

describe('JobStarted', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $jobId = JobId::generate();
        $jobUuid = 'job-uuid-456';
        $jobClass = 'App\\Jobs\\ProcessDataJob';
        $attempt = 2;
        $workerId = 'worker-abc123';
        $occurredAt = CarbonImmutable::now();

        $event = new JobStarted(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobClass: $jobClass,
            attempt: $attempt,
            workerId: $workerId,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->jobId)->toBe($jobId);
        expect($event->jobUuid)->toBe($jobUuid);
        expect($event->jobClass)->toBe($jobClass);
        expect($event->attempt)->toBe($attempt);
        expect($event->workerId)->toBe($workerId);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null worker id', function (): void {
        $event = new JobStarted(
            workflowId: WorkflowId::generate(),
            stepRunId: StepRunId::generate(),
            jobId: JobId::generate(),
            jobUuid: 'job-uuid-789',
            jobClass: 'App\\Jobs\\SomeJob',
            attempt: 1,
            workerId: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->workerId)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(JobStarted::class)->toBeImmutable();
    });
});

describe('JobSucceeded', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $jobId = JobId::generate();
        $jobUuid = 'job-uuid-success';
        $jobClass = 'App\\Jobs\\ProcessDataJob';
        $attempt = 1;
        $runtimeMs = 250;
        $occurredAt = CarbonImmutable::now();

        $event = new JobSucceeded(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobClass: $jobClass,
            attempt: $attempt,
            runtimeMs: $runtimeMs,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->jobId)->toBe($jobId);
        expect($event->jobUuid)->toBe($jobUuid);
        expect($event->jobClass)->toBe($jobClass);
        expect($event->attempt)->toBe($attempt);
        expect($event->runtimeMs)->toBe($runtimeMs);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null runtime', function (): void {
        $event = new JobSucceeded(
            workflowId: WorkflowId::generate(),
            stepRunId: StepRunId::generate(),
            jobId: JobId::generate(),
            jobUuid: 'job-uuid-no-runtime',
            jobClass: 'App\\Jobs\\SomeJob',
            attempt: 1,
            runtimeMs: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->runtimeMs)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(JobSucceeded::class)->toBeImmutable();
    });
});

describe('JobFailed', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $jobId = JobId::generate();
        $jobUuid = 'job-uuid-failed';
        $jobClass = 'App\\Jobs\\ProcessDataJob';
        $attempt = 3;
        $failureClass = 'RuntimeException';
        $failureMessage = 'Connection refused';
        $runtimeMs = 100;
        $occurredAt = CarbonImmutable::now();

        $event = new JobFailed(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            jobId: $jobId,
            jobUuid: $jobUuid,
            jobClass: $jobClass,
            attempt: $attempt,
            failureClass: $failureClass,
            failureMessage: $failureMessage,
            runtimeMs: $runtimeMs,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->stepRunId)->toBe($stepRunId);
        expect($event->jobId)->toBe($jobId);
        expect($event->jobUuid)->toBe($jobUuid);
        expect($event->jobClass)->toBe($jobClass);
        expect($event->attempt)->toBe($attempt);
        expect($event->failureClass)->toBe($failureClass);
        expect($event->failureMessage)->toBe($failureMessage);
        expect($event->runtimeMs)->toBe($runtimeMs);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null failure details', function (): void {
        $event = new JobFailed(
            workflowId: WorkflowId::generate(),
            stepRunId: StepRunId::generate(),
            jobId: JobId::generate(),
            jobUuid: 'job-uuid-null-failure',
            jobClass: 'App\\Jobs\\SomeJob',
            attempt: 1,
            failureClass: null,
            failureMessage: null,
            runtimeMs: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->failureClass)->toBeNull();
        expect($event->failureMessage)->toBeNull();
        expect($event->runtimeMs)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(JobFailed::class)->toBeImmutable();
    });
});
