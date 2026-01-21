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
            $stepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->isFinalized())->toBeFalse();
        });

        it('returns not ready when jobs are still running', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 2);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createRunningJob($stepRun, 'job-2');

            $stepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->isFinalized())->toBeFalse();
        });

        it('finalizes as succeeded when all jobs succeed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 2);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');

            $stepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->isFinalized())->toBeTrue();
            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('finalizes as failed when a job fails for single job step', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 1);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createFailedJob($stepRun, 'job-1');

            $stepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->isFinalized())->toBeTrue();
            expect($result->stepRun()->isFailed())->toBeTrue();
        });

        it('saves step run after finalization', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 1);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');

            $stepDefinition = SingleJobStepDefinition::create(
                $this->stepKey,
                'Test Step',
                TestJob::class,
            );

            $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            $savedStepRun = $this->stepRunRepository->find($stepRun->id);
            expect($savedStepRun?->isSucceeded())->toBeTrue();
        });
    });

    describe('fan-out success criteria', function (): void {
        it('succeeds with all criteria when all jobs succeed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');
            $this->createSucceededJob($stepRun, 'job-3');

            $stepDefinition = $this->createFanOutStep(SuccessCriteria::All);

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('fails with all criteria when one job fails', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');
            $this->createFailedJob($stepRun, 'job-3');

            $stepDefinition = $this->createFanOutStep(SuccessCriteria::All);

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->stepRun()->isFailed())->toBeTrue();
        });

        it('succeeds with majority criteria when majority succeed', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');
            $this->createFailedJob($stepRun, 'job-3');

            $stepDefinition = $this->createFanOutStep(SuccessCriteria::Majority);

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('fails with majority criteria when majority fail', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createFailedJob($stepRun, 'job-2');
            $this->createFailedJob($stepRun, 'job-3');

            $stepDefinition = $this->createFanOutStep(SuccessCriteria::Majority);

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->stepRun()->isFailed())->toBeTrue();
        });

        it('succeeds with best effort criteria when at least one succeeds', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 3);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createFailedJob($stepRun, 'job-2');
            $this->createFailedJob($stepRun, 'job-3');

            $stepDefinition = $this->createFanOutStep(SuccessCriteria::BestEffort);

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('succeeds with n-of-m criteria when minimum is met', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');
            $this->createSucceededJob($stepRun, 'job-3');
            $this->createFailedJob($stepRun, 'job-4');
            $this->createFailedJob($stepRun, 'job-5');

            $stepDefinition = $this->createFanOutStep(NOfMCriteria::atLeast(3));

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

            expect($result->stepRun()->isSucceeded())->toBeTrue();
        });

        it('fails with n-of-m criteria when minimum is not met', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');
            $this->createFailedJob($stepRun, 'job-3');
            $this->createFailedJob($stepRun, 'job-4');
            $this->createFailedJob($stepRun, 'job-5');

            $stepDefinition = $this->createFanOutStep(NOfMCriteria::atLeast(3));

            $result = $this->finalizer->tryFinalize($stepRun, $stepDefinition);

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

            $this->createSucceededJob($stepRun, 'job-1');

            expect($this->finalizer->isReadyForFinalization($stepRun))->toBeTrue();
        });
    });

    describe('calculateJobStats', function (): void {
        it('calculates correct job statistics', function (): void {
            $stepRun = StepRun::create($this->workflowId, $this->stepKey, totalJobCount: 5);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $this->createSucceededJob($stepRun, 'job-1');
            $this->createSucceededJob($stepRun, 'job-2');
            $this->createFailedJob($stepRun, 'job-3');
            $this->createRunningJob($stepRun, 'job-4');
            $this->createDispatchedJob($stepRun, 'job-5');

            $stats = $this->finalizer->calculateJobStats($stepRun);

            expect($stats->total)->toBe(5);
            expect($stats->succeeded)->toBe(2);
            expect($stats->failed)->toBe(1);
            expect($stats->running)->toBe(1);
            expect($stats->dispatched)->toBe(1);
        });
    });
});

function createSucceededJob(StepRun $stepRun, string $jobUuid): void
{
    $job = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $job->start('worker-1');
    $job->succeed();
    test()->jobLedgerRepository->save($job);
}

function createFailedJob(StepRun $stepRun, string $jobUuid): void
{
    $job = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $job->start('worker-1');
    $job->fail('Error', 'Test error');
    test()->jobLedgerRepository->save($job);
}

function createRunningJob(StepRun $stepRun, string $jobUuid): void
{
    $job = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    $job->start('worker-1');
    test()->jobLedgerRepository->save($job);
}

function createDispatchedJob(StepRun $stepRun, string $jobUuid): void
{
    $job = JobRecord::create(
        $stepRun->workflowId,
        $stepRun->id,
        $jobUuid,
        TestJob::class,
        'default',
    );
    test()->jobLedgerRepository->save($job);
}

function createFanOutStep(SuccessCriteria|NOfMCriteria $criteria): FanOutStepDefinition
{
    return FanOutStepDefinition::create(
        test()->stepKey,
        'Test Fan-Out Step',
        TestJob::class,
        static fn () => [],
        successCriteria: $criteria,
    );
}
