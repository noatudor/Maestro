<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\JobLedgerHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\StepRunHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentJobLedgerRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepRunRepository;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

describe('Job dispatch idempotency with real database', function (): void {
    beforeEach(function (): void {
        $this->jobLedgerHydrator = new JobLedgerHydrator();
        $this->stepRunHydrator = new StepRunHydrator();
        $this->jobLedgerRepository = new EloquentJobLedgerRepository($this->jobLedgerHydrator);
        $this->stepRunRepository = new EloquentStepRunRepository($this->stepRunHydrator);
        $this->workflowId = WorkflowId::generate();
    });

    describe('findByJobUuid', function (): void {
        it('finds job by queue uuid', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $jobUuid = Uuid::uuid7()->toString();
            $job = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: $jobUuid,
                jobClass: 'TestJob',
                queue: 'default',
            );
            $this->jobLedgerRepository->save($job);

            $found = $this->jobLedgerRepository->findByJobUuid($jobUuid);

            expect($found)->not->toBeNull();
            expect($found->jobUuid)->toBe($jobUuid);
        });

        it('returns null when job uuid does not exist', function (): void {
            $nonExistentUuid = Uuid::uuid7()->toString();

            $found = $this->jobLedgerRepository->findByJobUuid($nonExistentUuid);

            expect($found)->toBeNull();
        });
    });

    describe('existsByJobUuid', function (): void {
        it('returns true when job uuid exists', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $jobUuid = Uuid::uuid7()->toString();
            $job = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: $jobUuid,
                jobClass: 'TestJob',
                queue: 'default',
            );
            $this->jobLedgerRepository->save($job);

            $exists = $this->jobLedgerRepository->existsByJobUuid($jobUuid);

            expect($exists)->toBeTrue();
        });

        it('returns false when job uuid does not exist', function (): void {
            $nonExistentUuid = Uuid::uuid7()->toString();

            $exists = $this->jobLedgerRepository->existsByJobUuid($nonExistentUuid);

            expect($exists)->toBeFalse();
        });
    });

    describe('idempotency check pattern', function (): void {
        it('prevents duplicate job dispatch when job already exists', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $jobUuid = Uuid::uuid7()->toString();

            $existingJob = $this->jobLedgerRepository->findByJobUuid($jobUuid);
            expect($existingJob)->toBeNull();

            $job = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: $jobUuid,
                jobClass: 'TestJob',
                queue: 'default',
            );
            $this->jobLedgerRepository->save($job);

            $existingJobAfterSave = $this->jobLedgerRepository->findByJobUuid($jobUuid);
            expect($existingJobAfterSave)->not->toBeNull();
        });
    });

    describe('updateStatusAtomically', function (): void {
        it('atomically updates job status when from state matches', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $jobUuid = Uuid::uuid7()->toString();
            $job = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: $jobUuid,
                jobClass: 'TestJob',
                queue: 'default',
            );
            $this->jobLedgerRepository->save($job);

            $result = $this->jobLedgerRepository->updateStatusAtomically(
                $job->id,
                JobState::Dispatched,
                JobState::Running,
            );

            expect($result)->toBeTrue();

            $reloaded = $this->jobLedgerRepository->findOrFail($job->id);
            expect($reloaded->status())->toBe(JobState::Running);
        });

        it('returns false when from state does not match', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $jobUuid = Uuid::uuid7()->toString();
            $job = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: $jobUuid,
                jobClass: 'TestJob',
                queue: 'default',
            );
            $job->start('worker-1');
            $this->jobLedgerRepository->save($job);

            $result = $this->jobLedgerRepository->updateStatusAtomically(
                $job->id,
                JobState::Dispatched,
                JobState::Running,
            );

            expect($result)->toBeFalse();
        });

        it('only one status update succeeds in concurrent scenario', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $jobUuid = Uuid::uuid7()->toString();
            $job = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: $jobUuid,
                jobClass: 'TestJob',
                queue: 'default',
            );
            $this->jobLedgerRepository->save($job);

            $firstResult = $this->jobLedgerRepository->updateStatusAtomically(
                $job->id,
                JobState::Dispatched,
                JobState::Running,
            );

            $secondResult = $this->jobLedgerRepository->updateStatusAtomically(
                $job->id,
                JobState::Dispatched,
                JobState::Succeeded,
            );

            expect($firstResult)->toBeTrue();
            expect($secondResult)->toBeFalse();

            $reloaded = $this->jobLedgerRepository->findOrFail($job->id);
            expect($reloaded->status())->toBe(JobState::Running);
        });
    });

    describe('areAllJobsTerminalForStepRun', function (): void {
        it('returns true when all jobs are terminal', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $job1 = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: Uuid::uuid7()->toString(),
                jobClass: 'TestJob',
                queue: 'default',
            );
            $job1->start('worker-1');
            $job1->succeed();
            $this->jobLedgerRepository->save($job1);

            $job2 = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: Uuid::uuid7()->toString(),
                jobClass: 'TestJob',
                queue: 'default',
            );
            $job2->start('worker-2');
            $job2->fail('error', 'Test failure');
            $this->jobLedgerRepository->save($job2);

            $allTerminal = $this->jobLedgerRepository->areAllJobsTerminalForStepRun($stepRun->id);

            expect($allTerminal)->toBeTrue();
        });

        it('returns false when some jobs are still in progress', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $job1 = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: Uuid::uuid7()->toString(),
                jobClass: 'TestJob',
                queue: 'default',
            );
            $job1->start('worker-1');
            $job1->succeed();
            $this->jobLedgerRepository->save($job1);

            $job2 = JobRecord::create(
                workflowId: $this->workflowId,
                stepRunId: $stepRun->id,
                jobUuid: Uuid::uuid7()->toString(),
                jobClass: 'TestJob',
                queue: 'default',
            );
            $job2->start('worker-2');
            $this->jobLedgerRepository->save($job2);

            $allTerminal = $this->jobLedgerRepository->areAllJobsTerminalForStepRun($stepRun->id);

            expect($allTerminal)->toBeFalse();
        });

        it('returns true when no jobs exist for step run', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->stepRunRepository->save($stepRun);

            $allTerminal = $this->jobLedgerRepository->areAllJobsTerminalForStepRun($stepRun->id);

            expect($allTerminal)->toBeTrue();
        });
    });
});
