<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Job\Middleware\JobLifecycleMiddleware;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobLifecycleMiddleware', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryJobLedgerRepository();
        $this->workerId = 'test-worker-1';
        $this->middleware = new JobLifecycleMiddleware($this->repository, $this->workerId);

        $this->workflowId = WorkflowId::generate();
        $this->stepRunId = StepRunId::generate();
        $this->jobUuid = 'test-job-uuid-456';
    });

    it('marks job as running on start', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $this->repository->save($jobRecord);

        $this->middleware->handle($job, static fn (): null => null);

        $updatedJob = $this->repository->findByJobUuid($this->jobUuid);

        expect($updatedJob)->not->toBeNull();
        expect($updatedJob->status())->toBe(JobState::Succeeded);
        expect($updatedJob->workerId())->toBe($this->workerId);
    });

    it('marks job as succeeded on completion', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $this->repository->save($jobRecord);

        $this->middleware->handle($job, static fn (): null => null);

        $updatedJob = $this->repository->findByJobUuid($this->jobUuid);

        expect($updatedJob->status())->toBe(JobState::Succeeded);
        expect($updatedJob->finishedAt())->not->toBeNull();
    });

    it('marks job as failed on exception', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);
        $job->shouldFail = true;
        $job->failureMessage = 'Something went wrong';

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $this->repository->save($jobRecord);

        try {
            $this->middleware->handle($job, static fn () => throw new RuntimeException('Something went wrong'));
        } catch (RuntimeException) {
            // Expected
        }

        $updatedJob = $this->repository->findByJobUuid($this->jobUuid);

        expect($updatedJob->status())->toBe(JobState::Failed);
        expect($updatedJob->failureClass())->toBe(RuntimeException::class);
        expect($updatedJob->failureMessage())->toBe('Something went wrong');
    });

    it('rethrows exception after recording failure', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $jobRecord = JobRecord::create(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
            TestOrchestratedJob::class,
            'default',
        );
        $this->repository->save($jobRecord);

        expect(fn () => $this->middleware->handle($job, static fn () => throw new RuntimeException('Test error')))
            ->toThrow(RuntimeException::class, 'Test error');
    });

    it('skips execution if job record not found', function (): void {
        $job = new TestOrchestratedJob($this->workflowId, $this->stepRunId, $this->jobUuid);

        $executed = false;
        $this->middleware->handle($job, static function () use (&$executed): void {
            $executed = true;
        });

        expect($executed)->toBeTrue();
    });

    it('skips execution if job is already terminal', function (): void {
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
});
