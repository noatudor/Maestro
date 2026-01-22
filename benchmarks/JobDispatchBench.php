<?php

declare(strict_types=1);

namespace Maestro\Workflow\Benchmarks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Mockery;
use PhpBench\Attributes as Bench;
use Ramsey\Uuid\Uuid;

/**
 * Benchmarks for job dispatch operations.
 *
 * Target: < 2ms for job dispatch
 */
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class JobDispatchBench
{
    private InMemoryJobLedgerRepository $jobLedgerRepository;

    private JobDispatchService $jobDispatchService;

    private WorkflowInstance $workflowInstance;

    private StepRun $stepRun;

    public function setUp(): void
    {
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();

        $dispatcherMock = Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch');

        $eventDispatcherMock = Mockery::mock(EventDispatcher::class);
        $eventDispatcherMock->shouldReceive('dispatch');

        $this->jobDispatchService = new JobDispatchService(
            $dispatcherMock,
            $this->jobLedgerRepository,
            $eventDispatcherMock,
        );

        $this->workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('bench-workflow'),
            DefinitionVersion::fromString('1.0.0'),
        );
        $this->workflowInstance->start(StepKey::fromString('step-1'));

        $this->stepRun = StepRun::create(
            $this->workflowInstance->id,
            StepKey::fromString('step-1'),
            totalJobCount: 1,
        );
        $this->stepRun->start();
    }

    public function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * Benchmark creating a job record.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 2ms')]
    public function benchCreateJobRecord(): void
    {
        JobRecord::create(
            $this->workflowInstance->id,
            $this->stepRun->id,
            Uuid::uuid7()->toString(),
            DummyJob::class,
            'default',
        );
    }

    /**
     * Benchmark saving a job record to repository.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 2ms')]
    public function benchSaveJobRecord(): void
    {
        $jobRecord = JobRecord::create(
            $this->workflowInstance->id,
            $this->stepRun->id,
            Uuid::uuid7()->toString(),
            DummyJob::class,
            'default',
        );

        $this->jobLedgerRepository->save($jobRecord);
    }

    /**
     * Benchmark job record state transitions.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchJobRecordStateTransitions(): void
    {
        $jobRecord = JobRecord::create(
            $this->workflowInstance->id,
            $this->stepRun->id,
            Uuid::uuid7()->toString(),
            DummyJob::class,
            'default',
        );

        $jobRecord->start('worker-1');
        $jobRecord->succeed();
    }

    /**
     * Benchmark finding jobs by step run ID.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchFindJobsByStepRunId(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $jobRecord = JobRecord::create(
                $this->workflowInstance->id,
                $this->stepRun->id,
                Uuid::uuid7()->toString(),
                DummyJob::class,
                'default',
            );
            $this->jobLedgerRepository->save($jobRecord);
        }

        $this->jobLedgerRepository->findByStepRunId($this->stepRun->id);
    }

    /**
     * Benchmark finding job by UUID.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchFindJobByUuid(): void
    {
        $uuid = Uuid::uuid7()->toString();
        $jobRecord = JobRecord::create(
            $this->workflowInstance->id,
            $this->stepRun->id,
            $uuid,
            DummyJob::class,
            'default',
        );
        $this->jobLedgerRepository->save($jobRecord);

        $this->jobLedgerRepository->findByJobUuid($uuid);
    }

    /**
     * Benchmark checking if job exists.
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Assert('mode(variant.time.avg) < 1ms')]
    public function benchJobExistsCheck(): void
    {
        $uuid = Uuid::uuid7()->toString();
        $jobRecord = JobRecord::create(
            $this->workflowInstance->id,
            $this->stepRun->id,
            $uuid,
            DummyJob::class,
            'default',
        );
        $this->jobLedgerRepository->save($jobRecord);

        $this->jobLedgerRepository->existsByJobUuid($uuid);
    }
}
