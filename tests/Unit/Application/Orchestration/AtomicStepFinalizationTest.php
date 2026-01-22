<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepFinalizer atomic finalization', function (): void {
    beforeEach(function (): void {
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->eventDispatcher = Mockery::mock(EventDispatcher::class);
        $this->eventDispatcher->shouldReceive('dispatch');

        $this->finalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
            $this->eventDispatcher,
        );
    });

    it('uses atomic update to finalize step as succeeded', function (): void {
        $workflowId = WorkflowId::generate();
        $stepKey = StepKey::fromString('test-step');

        $stepRun = StepRun::create(
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: 1,
        );
        $stepRun->start();
        $this->stepRunRepository->save($stepRun);

        $jobRecord = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $jobRecord->start('worker-1');
        $jobRecord->succeed();
        $this->jobLedgerRepository->save($jobRecord);

        $mock = Mockery::mock(StepDefinition::class);
        $mock->shouldReceive('key')->andReturn($stepKey);

        $result = $this->finalizer->tryFinalize($stepRun, $mock);

        expect($result->isFinalized())->toBeTrue();
        expect($result->wonRace())->toBeTrue();
        expect($result->stepRun()->status())->toBe(StepState::Succeeded);
    });

    it('uses atomic update to finalize step as failed', function (): void {
        $workflowId = WorkflowId::generate();
        $stepKey = StepKey::fromString('test-step');

        $stepRun = StepRun::create(
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: 1,
        );
        $stepRun->start();
        $this->stepRunRepository->save($stepRun);

        $jobRecord = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $jobRecord->start('worker-1');
        $jobRecord->fail('error', 'Test error');
        $this->jobLedgerRepository->save($jobRecord);

        $mock = Mockery::mock(StepDefinition::class);
        $mock->shouldReceive('key')->andReturn($stepKey);

        $result = $this->finalizer->tryFinalize($stepRun, $mock);

        expect($result->isFinalized())->toBeTrue();
        expect($result->wonRace())->toBeTrue();
        expect($result->stepRun()->status())->toBe(StepState::Failed);
    });

    it('returns alreadyFinalized when another worker finalized first', function (): void {
        $workflowId = WorkflowId::generate();
        $stepKey = StepKey::fromString('test-step');

        $stepRun = StepRun::create(
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: 1,
        );
        $stepRun->start();
        $this->stepRunRepository->save($stepRun);

        $jobRecord = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $jobRecord->start('worker-1');
        $jobRecord->succeed();
        $this->jobLedgerRepository->save($jobRecord);

        $repository = new class($stepRun) extends InMemoryStepRunRepository
        {
            public function __construct(StepRun $stepRun)
            {
                $this->save($stepRun);
            }

            public function finalizeAsSucceeded(StepRunId $stepRunId, CarbonImmutable $finishedAt): bool
            {
                return false;
            }
        };

        $eventDispatcherMock = Mockery::mock(EventDispatcher::class);
        $eventDispatcherMock->shouldReceive('dispatch');

        $finalizer = new StepFinalizer($repository, $this->jobLedgerRepository, $eventDispatcherMock);

        $mock = Mockery::mock(StepDefinition::class);
        $mock->shouldReceive('key')->andReturn($stepKey);

        $stepFinalizationResult = $finalizer->tryFinalize($stepRun, $mock);

        expect($stepFinalizationResult->isFinalized())->toBeTrue();
        expect($stepFinalizationResult->wonRace())->toBeFalse();
    });

    it('returns notReady when step is not running', function (): void {
        $workflowId = WorkflowId::generate();
        $stepKey = StepKey::fromString('test-step');

        $stepRun = StepRun::create(
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: 1,
        );
        $this->stepRunRepository->save($stepRun);

        $mock = Mockery::mock(StepDefinition::class);

        $result = $this->finalizer->tryFinalize($stepRun, $mock);

        expect($result->isFinalized())->toBeFalse();
        expect($result->wonRace())->toBeFalse();
    });

    it('returns notReady when jobs are still running', function (): void {
        $workflowId = WorkflowId::generate();
        $stepKey = StepKey::fromString('test-step');

        $stepRun = StepRun::create(
            workflowId: $workflowId,
            stepKey: $stepKey,
            attempt: 1,
        );
        $stepRun->start();
        $this->stepRunRepository->save($stepRun);

        $jobRecord = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $jobRecord->start('worker-1');
        $jobRecord->succeed();
        $this->jobLedgerRepository->save($jobRecord);

        $job2 = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-2',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $job2->start('worker-2');
        $this->jobLedgerRepository->save($job2);

        $mock = Mockery::mock(StepDefinition::class);

        $result = $this->finalizer->tryFinalize($stepRun, $mock);

        expect($result->isFinalized())->toBeFalse();
        expect($result->wonRace())->toBeFalse();
    });
});
