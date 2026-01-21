<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Job\Middleware\IdempotencyMiddleware;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('IdempotencyMiddleware', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryJobLedgerRepository();
        $this->middleware = new IdempotencyMiddleware($this->repository);

        $this->workflowId = WorkflowId::generate();
        $this->stepRunId = StepRunId::generate();
        $this->jobUuid = 'test-job-uuid-789';
    });

    it('allows execution when no existing job record', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $executed = false;
        $this->middleware->handle($job, static function () use (&$executed): void {
            $executed = true;
        });

        expect($executed)->toBeTrue();
    });

    it('allows execution when existing job is not terminal', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $this->repository->save($jobRecord);

        $executed = false;
        $this->middleware->handle($job, static function () use (&$executed): void {
            $executed = true;
        });

        expect($executed)->toBeTrue();
    });

    it('skips execution when existing job is succeeded', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $jobRecord->start();
        $jobRecord->succeed();
        $this->repository->save($jobRecord);

        $executed = false;
        $this->middleware->handle($job, static function () use (&$executed): void {
            $executed = true;
        });

        expect($executed)->toBeFalse();
    });

    it('skips execution when existing job is failed', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $jobRecord->start();
        $jobRecord->fail('TestException', 'Test failure');
        $this->repository->save($jobRecord);

        $executed = false;
        $this->middleware->handle($job, static function () use (&$executed): void {
            $executed = true;
        });

        expect($executed)->toBeFalse();
    });
});
