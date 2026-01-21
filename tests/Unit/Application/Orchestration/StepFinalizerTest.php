<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Definition\Config\NOfMCriteria;
use Maestro\Workflow\Definition\Steps\FanOutStepDefinition;
use Maestro\Workflow\Definition\Steps\SingleJobStepDefinition;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\SuccessCriteria;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepFinalizer', function (): void {
    beforeEach(function (): void {
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->finalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
        );

        $this->workflowId = WorkflowId::generate();
        $this->stepKey = StepKey::fromString('test-step');
    });

    describe('tryFinalize', function (): void {
        it('returns not ready when step is not running', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $singleJobStepDefinition);

            expect($result->isFinalized())->toBeFalse();
        });

        it('returns not ready when jobs are still running', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 2);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createRunningJob($this->jobLedgerRepository, $stepRun, 'job-2');

            $singleJobStepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $singleJobStepDefinition);

            expect($result->isFinalized())->toBeFalse();
        });

        it('finalizes as succeeded when all jobs succeed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 2);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');

            $singleJobStepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $singleJobStepDefinition);

            expect($result->isFinalized())->toBeTrue();
            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('finalizes as failed when a job fails for single job step', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 1);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-1');

            $singleJobStepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $singleJobStepDefinition);

            expect($result->isFinalized())->toBeTrue();
            expect($result->stepRun()->isFailed())->toBeTrue();
        });

        it('saves step run after finalization', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 1);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');

            $singleJobStepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $this->finalizer->tryFinalize($stepRun, $singleJobStepDefinition);

            $savedStepRun = $this->stepRunRepository->find($stepRun->id);
            expect($savedStepRun?->isSucceeded())->toBeTrue();
        });
    });

    describe('fan-out success criteria', function (): void {
        it('succeeds with all criteria when all jobs succeed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-3');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, SuccessCriteria::All);

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('fails with all criteria when one job fails', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-3');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, SuccessCriteria::All);

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isFailed())->toBeTrue();
        });

        it('succeeds with majority criteria when majority succeed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-3');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, SuccessCriteria::Majority);

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('fails with majority criteria when majority fail', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-3');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, SuccessCriteria::Majority);

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isFailed())->toBeTrue();
        });

        it('succeeds with best effort criteria when at least one succeeds', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-3');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, SuccessCriteria::BestEffort);

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('succeeds with n-of-m criteria when minimum is met', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-3');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-4');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-5');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, NOfMCriteria::atLeast(3));

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('fails with n-of-m criteria when minimum is not met', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-3');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-4');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-5');

            $fanOutStepDefinition = createFanOutStep($this->stepKey, NOfMCriteria::atLeast(3));

            $result = $this->finalizer->tryFinalize($stepRun, $fanOutStepDefinition);

            expect($result->stepRun()->isFailed())->toBeTrue();
        });
    });

    describe('isReadyForFinalization', function (): void {
        it('returns false when step is not running', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey);

            expect($this->finalizer->isReadyForFinalization($stepRun))->toBeFalse();
        });

        it('returns true when all jobs complete', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 1);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');

            expect($this->finalizer->isReadyForFinalization($stepRun))->toBeTrue();
        });
    });

    describe('calculateJobStats', function (): void {
        it('calculates correct job statistics', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-1');
            createSucceededJob($this->jobLedgerRepository, $stepRun, 'job-2');
            createFailedJob($this->jobLedgerRepository, $stepRun, 'job-3');
            createRunningJob($this->jobLedgerRepository, $stepRun, 'job-4');
            createDispatchedJob($this->jobLedgerRepository, $stepRun, 'job-5');

            $stats = $this->finalizer->calculateJobStats($stepRun);

            expect($stats->total)->toBe(5);
            expect($stats->succeeded)->toBe(2);
            expect($stats->failed)->toBe(1);
            expect($stats->running)->toBe(1);
            expect($stats->dispatched)->toBe(1);
        });
    });
});

function createSucceededJob(InMemoryJobLedgerRepository $inMemoryJobLedgerRepository, StepRun $stepRun, string $jobUuid): void
{
    $jobRecord = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $jobRecord->start('worker-1');
    $jobRecord->succeed();
    $inMemoryJobLedgerRepository->save($jobRecord);
}

function createFailedJob(InMemoryJobLedgerRepository $inMemoryJobLedgerRepository, StepRun $stepRun, string $jobUuid): void
{
    $jobRecord = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $jobRecord->start('worker-1');
    $jobRecord->fail('Error', 'Test error');
    $inMemoryJobLedgerRepository->save($jobRecord);
}

function createRunningJob(InMemoryJobLedgerRepository $inMemoryJobLedgerRepository, StepRun $stepRun, string $jobUuid): void
{
    $jobRecord = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $jobRecord->start('worker-1');
    $inMemoryJobLedgerRepository->save($jobRecord);
}

function createDispatchedJob(InMemoryJobLedgerRepository $inMemoryJobLedgerRepository, StepRun $stepRun, string $jobUuid): void
{
    $jobRecord = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $inMemoryJobLedgerRepository->save($jobRecord);
}

function createFanOutStep(StepKey $stepKey, SuccessCriteria|NOfMCriteria $criteria): FanOutStepDefinition
{
    return FanOutStepDefinition::create(
        $stepKey,
        'Test Fan-Out Step',
        TestJob::class,
        static fn (): array => [],
        successCriteria: $criteria,
    );
}
