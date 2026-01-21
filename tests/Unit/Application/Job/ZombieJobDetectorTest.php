<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Application\Job\ZombieJobDetectionResult;
use Maestro\Workflow\Application\Job\ZombieJobDetector;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('ZombieJobDetector', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryJobLedgerRepository();
        $this->detector = new ZombieJobDetector($this->repository);
    });

    describe('detect', function (): void {
        it('returns empty result when no zombie jobs exist', function (): void {
            $result = $this->detector->detect();

            expect($result)->toBeInstanceOf(ZombieJobDetectionResult::class);
            expect($result->hasZombies())->toBeFalse();
            expect($result->markedFailedCount)->toBe(0);
            expect($result->detectedJobs)->toBeEmpty();
            expect($result->affectedWorkflowIds)->toBeEmpty();
        });

        it('detects running jobs past timeout threshold', function (): void {
            $workflowId = WorkflowId::generate();
            $stepRunId = StepRunId::generate();

            $jobRecord = JobRecord::create(
                $workflowId,
                $stepRunId,
                'zombie-job-uuid',
                TestOrchestratedJob::class,
                'default',
            );

            CarbonImmutable::setTestNow(CarbonImmutable::now()->subHours(2));
            $jobRecord->start('worker-1');
            CarbonImmutable::setTestNow();

            $this->repository->save($jobRecord);

            $result = $this->detector->detect(30);

            expect($result->hasZombies())->toBeTrue();
            expect($result->markedFailedCount)->toBe(1);
            expect($result->detectedJobs)->toHaveCount(1);
            expect($result->affectedWorkflowIds)->toHaveCount(1);
            expect($result->affectedWorkflowIds[0]->value)->toBe($workflowId->value);

            $updatedJob = $this->repository->findByJobUuid('zombie-job-uuid');
            expect($updatedJob->status())->toBe(JobState::Failed);
            expect($updatedJob->failureClass())->toBe('Maestro\\Workflow\\Exceptions\\ZombieJobException');
        });

        it('does not detect jobs within timeout threshold', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'recent-job-uuid',
                TestOrchestratedJob::class,
                'default',
            );
            $jobRecord->start('worker-1');
            $this->repository->save($jobRecord);

            $result = $this->detector->detect(30);

            expect($result->hasZombies())->toBeFalse();
        });

        it('does not detect dispatched jobs', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'dispatched-job-uuid',
                TestOrchestratedJob::class,
                'default',
            );
            $this->repository->save($jobRecord);

            $result = $this->detector->detect(0);

            expect($result->hasZombies())->toBeFalse();
        });

        it('does not detect terminal jobs', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'succeeded-job-uuid',
                TestOrchestratedJob::class,
                'default',
            );
            $jobRecord->start('worker-1');
            $jobRecord->succeed();
            $this->repository->save($jobRecord);

            $result = $this->detector->detect(0);

            expect($result->hasZombies())->toBeFalse();
        });

        it('aggregates multiple affected workflows', function (): void {
            $workflowId1 = WorkflowId::generate();
            $workflowId2 = WorkflowId::generate();

            $jobRecord = JobRecord::create(
                $workflowId1,
                StepRunId::generate(),
                'zombie-1',
                TestOrchestratedJob::class,
                'default',
            );

            $job2 = JobRecord::create(
                $workflowId2,
                StepRunId::generate(),
                'zombie-2',
                TestOrchestratedJob::class,
                'default',
            );

            CarbonImmutable::setTestNow(CarbonImmutable::now()->subHours(2));
            $jobRecord->start('worker-1');
            $job2->start('worker-2');
            CarbonImmutable::setTestNow();

            $this->repository->save($jobRecord);
            $this->repository->save($job2);

            $result = $this->detector->detect(30);

            expect($result->markedFailedCount)->toBe(2);
            expect($result->affectedWorkflowIds)->toHaveCount(2);
        });

        it('deduplicates workflow ids for jobs in same workflow', function (): void {
            $workflowId = WorkflowId::generate();

            $jobRecord = JobRecord::create(
                $workflowId,
                StepRunId::generate(),
                'zombie-same-1',
                TestOrchestratedJob::class,
                'default',
            );

            $job2 = JobRecord::create(
                $workflowId,
                StepRunId::generate(),
                'zombie-same-2',
                TestOrchestratedJob::class,
                'default',
            );

            CarbonImmutable::setTestNow(CarbonImmutable::now()->subHours(2));
            $jobRecord->start('worker-1');
            $job2->start('worker-2');
            CarbonImmutable::setTestNow();

            $this->repository->save($jobRecord);
            $this->repository->save($job2);

            $result = $this->detector->detect(30);

            expect($result->markedFailedCount)->toBe(2);
            expect($result->affectedWorkflowIds)->toHaveCount(1);
        });
    });

    describe('detectStaleDispatched', function (): void {
        it('returns empty result when no stale jobs exist', function (): void {
            $result = $this->detector->detectStaleDispatched();

            expect($result->hasZombies())->toBeFalse();
        });

        it('detects dispatched jobs past timeout threshold', function (): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->subHours(2));

            $workflowId = WorkflowId::generate();
            $jobRecord = JobRecord::create(
                $workflowId,
                StepRunId::generate(),
                'stale-job-uuid',
                TestOrchestratedJob::class,
                'default',
            );

            CarbonImmutable::setTestNow();

            $this->repository->save($jobRecord);

            $result = $this->detector->detectStaleDispatched(30);

            expect($result->hasZombies())->toBeTrue();
            expect($result->markedFailedCount)->toBe(1);

            $updatedJob = $this->repository->findByJobUuid('stale-job-uuid');
            expect($updatedJob->status())->toBe(JobState::Failed);
            expect($updatedJob->failureClass())->toBe('Maestro\\Workflow\\Exceptions\\StaleJobException');
        });

        it('does not detect recently dispatched jobs', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'recent-dispatch-uuid',
                TestOrchestratedJob::class,
                'default',
            );
            $this->repository->save($jobRecord);

            $result = $this->detector->detectStaleDispatched(30);

            expect($result->hasZombies())->toBeFalse();
        });

        it('does not detect running jobs', function (): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->subHours(2));

            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'running-job-uuid',
                TestOrchestratedJob::class,
                'default',
            );

            CarbonImmutable::setTestNow();

            $jobRecord->start('worker-1');
            $this->repository->save($jobRecord);

            $result = $this->detector->detectStaleDispatched(30);

            expect($result->hasZombies())->toBeFalse();
        });
    });
});

describe('ZombieJobDetectionResult', static function (): void {
    it('creates empty result', function (): void {
        $zombieJobDetectionResult = ZombieJobDetectionResult::empty();

        expect($zombieJobDetectionResult->detectedJobs)->toBeEmpty();
        expect($zombieJobDetectionResult->affectedWorkflowIds)->toBeEmpty();
        expect($zombieJobDetectionResult->markedFailedCount)->toBe(0);
        expect($zombieJobDetectionResult->hasZombies())->toBeFalse();
    });

    it('reports has zombies when count > 0', function (): void {
        $result = new ZombieJobDetectionResult([], [], 1);

        expect($result->hasZombies())->toBeTrue();
    });
});
