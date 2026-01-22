<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobDispatchService', function (): void {
    beforeEach(function (): void {
        $this->dispatcher = Mockery::mock(Dispatcher::class);
        $this->eventDispatcher = Mockery::mock(EventDispatcher::class);
        $this->eventDispatcher->shouldReceive('dispatch');
        $this->repository = new InMemoryJobLedgerRepository();
        $this->service = new JobDispatchService($this->dispatcher, $this->repository, $this->eventDispatcher);

        $this->workflowId = WorkflowId::generate();
        $this->stepRunId = StepRunId::generate();
    });

    describe('dispatch', function (): void {
        it('creates job ledger entry before dispatch', function (): void {
            $job = new TestOrchestratedJob(
                $this->workflowId,
                $this->stepRunId,
                'test-uuid-123',
            );

            $this->dispatcher->expects('dispatch')->once()->with($job);

            $this->service->dispatch($job, QueueConfiguration::default());

            $ledgerEntry = $this->repository->findByJobUuid('test-uuid-123');

            expect($ledgerEntry)->not->toBeNull();
            expect($ledgerEntry->jobClass)->toBe(TestOrchestratedJob::class);
            expect($ledgerEntry->status())->toBe(JobState::Dispatched);
        });

        it('returns job uuid', function (): void {
            $job = new TestOrchestratedJob(
                $this->workflowId,
                $this->stepRunId,
                'test-uuid-456',
            );

            $this->dispatcher->allows('dispatch');

            $result = $this->service->dispatch($job, QueueConfiguration::default());

            expect($result)->toBe('test-uuid-456');
        });

        it('applies queue configuration', function (): void {
            $job = new TestOrchestratedJob(
                $this->workflowId,
                $this->stepRunId,
                'test-uuid-789',
            );

            $this->dispatcher->allows('dispatch');

            $queueConfiguration = QueueConfiguration::create('high-priority', 'redis', 30);
            $this->service->dispatch($job, $queueConfiguration);

            expect($job->queue)->toBe('high-priority');
            expect($job->connection)->toBe('redis');
        });

        it('records correct queue name in ledger', function (): void {
            $job = new TestOrchestratedJob(
                $this->workflowId,
                $this->stepRunId,
                'test-uuid-queue',
            );

            $this->dispatcher->allows('dispatch');

            $queueConfiguration = QueueConfiguration::onQueue('emails');
            $this->service->dispatch($job, $queueConfiguration);

            $ledgerEntry = $this->repository->findByJobUuid('test-uuid-queue');

            expect($ledgerEntry->queue)->toBe('emails');
        });
    });

    describe('dispatchMany', function (): void {
        it('dispatches multiple jobs', function (): void {
            $jobs = [
                new TestOrchestratedJob($this->workflowId, $this->stepRunId, 'uuid-1'),
                new TestOrchestratedJob($this->workflowId, $this->stepRunId, 'uuid-2'),
                new TestOrchestratedJob($this->workflowId, $this->stepRunId, 'uuid-3'),
            ];

            $this->dispatcher->expects('dispatch')->times(3);

            $uuids = $this->service->dispatchMany($jobs, QueueConfiguration::default());

            expect($uuids)->toBe(['uuid-1', 'uuid-2', 'uuid-3']);
            expect($this->repository->all())->toHaveCount(3);
        });
    });

    describe('createJob', function (): void {
        it('creates job with generated uuid', function (): void {
            $job = $this->service->createJob(
                TestOrchestratedJob::class,
                $this->workflowId,
                $this->stepRunId,
            );

            expect($job)->toBeInstanceOf(TestOrchestratedJob::class);
            expect($job->workflowId)->toBe($this->workflowId);
            expect($job->stepRunId)->toBe($this->stepRunId);
            expect($job->jobUuid)->not->toBeEmpty();
        });

        it('generates unique uuids', function (): void {
            $job1 = $this->service->createJob(
                TestOrchestratedJob::class,
                $this->workflowId,
                $this->stepRunId,
            );

            $job2 = $this->service->createJob(
                TestOrchestratedJob::class,
                $this->workflowId,
                $this->stepRunId,
            );

            expect($job1->jobUuid)->not->toBe($job2->jobUuid);
        });
    });

    describe('generateJobUuid', function (): void {
        it('generates valid uuid', function (): void {
            $uuid = $this->service->generateJobUuid();

            expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
        });

        it('generates unique uuids', function (): void {
            $uuids = [];
            for ($i = 0; $i < 100; $i++) {
                $uuids[] = $this->service->generateJobUuid();
            }

            expect(array_unique($uuids))->toHaveCount(100);
        });
    });
});
