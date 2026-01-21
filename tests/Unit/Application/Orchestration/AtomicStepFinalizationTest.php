<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepFinalizer atomic finalization', function (): void {
    beforeEach(function (): void {
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();

        $this->finalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
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
        $stepRun->start(1);
        $this->stepRunRepository->save($stepRun);

        $job = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $job->start('worker-1');
        $job->succeed();
        $this->jobLedgerRepository->save($job);

        $stepDefinition = Mockery::mock(StepDefinition::class);
        $stepDefinition->shouldReceive('key')->andReturn($stepKey);

        $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

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
        $stepRun->start(1);
        $this->stepRunRepository->save($stepRun);

        $job = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $job->start('worker-1');
        $job->fail('error', 'Test error');
        $this->jobLedgerRepository->save($job);

        $stepDefinition = Mockery::mock(StepDefinition::class);
        $stepDefinition->shouldReceive('key')->andReturn($stepKey);

        $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

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
        $stepRun->start(1);
        $this->stepRunRepository->save($stepRun);

        $job = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $job->start('worker-1');
        $job->succeed();
        $this->jobLedgerRepository->save($job);

        $repository = new class($stepRun) extends InMemoryStepRunRepository
        {
            public function __construct(StepRun $stepRun)
            {
                parent::__construct();
                $this->save($stepRun);
            }

            public function finalizeAsSucceeded(Maestro\Workflow\ValueObjects\StepRunId $stepRunId, CarbonImmutable $finishedAt): bool
            {
                return false;
            }
        };

        $finalizer = new StepFinalizer($repository, $this->jobLedgerRepository);

        $stepDefinition = Mockery::mock(StepDefinition::class);
        $stepDefinition->shouldReceive('key')->andReturn($stepKey);

        $result = $finalizer->tryFinalize($stepRun, $stepDefinition);

        expect($result->isFinalized())->toBeTrue();
        expect($result->wonRace())->toBeFalse();
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

        $stepDefinition = Mockery::mock(StepDefinition::class);

        $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

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
        $stepRun->start(2);
        $this->stepRunRepository->save($stepRun);

        $job1 = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-1',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $job1->start('worker-1');
        $job1->succeed();
        $this->jobLedgerRepository->save($job1);

        $job2 = JobRecord::create(
            workflowId: $workflowId,
            stepRunId: $stepRun->id,
            jobUuid: 'job-uuid-2',
            jobClass: 'TestJob',
            queue: 'default',
        );
        $job2->start('worker-2');
        $this->jobLedgerRepository->save($job2);

        $stepDefinition = Mockery::mock(StepDefinition::class);

        $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

        expect($result->isFinalized())->toBeFalse();
        expect($result->wonRace())->toBeFalse();
    });
});
