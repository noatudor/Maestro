<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Http\Responses\JobRecordDTO;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobRecordDTO', static function (): void {
    describe('fromJobRecord', static function (): void {
        it('creates dto from dispatched job record', function (): void {
            $workflowId = WorkflowId::generate();
            $stepRunId = StepRunId::generate();
            $jobUuid = 'job-uuid-123';

            $jobRecord = JobRecord::create(
                $workflowId,
                $stepRunId,
                $jobUuid,
                'App\\Jobs\\TestJob',
                'default',
            );

            $jobRecordDTO = JobRecordDTO::fromJobRecord($jobRecord);

            expect($jobRecordDTO->id)->toBe($jobRecord->id->value);
            expect($jobRecordDTO->workflowId)->toBe($workflowId->value);
            expect($jobRecordDTO->stepRunId)->toBe($stepRunId->value);
            expect($jobRecordDTO->jobUuid)->toBe($jobUuid);
            expect($jobRecordDTO->jobClass)->toBe('App\\Jobs\\TestJob');
            expect($jobRecordDTO->queue)->toBe('default');
            expect($jobRecordDTO->status)->toBe(JobState::Dispatched);
            expect($jobRecordDTO->attempt)->toBe(1);
            expect($jobRecordDTO->startedAt)->toBeNull();
            expect($jobRecordDTO->finishedAt)->toBeNull();
        });

        it('creates dto from running job record', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'job-uuid',
                'App\\Jobs\\TestJob',
                'default',
            );
            $jobRecord->start('worker-1');

            $jobRecordDTO = JobRecordDTO::fromJobRecord($jobRecord);

            expect($jobRecordDTO->status)->toBe(JobState::Running);
            expect($jobRecordDTO->workerId)->toBe('worker-1');
            expect($jobRecordDTO->startedAt)->not->toBeNull();
        });

        it('creates dto from succeeded job record', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'job-uuid',
                'App\\Jobs\\TestJob',
                'default',
            );
            $jobRecord->start('worker-1');
            $jobRecord->succeed();

            $jobRecordDTO = JobRecordDTO::fromJobRecord($jobRecord);

            expect($jobRecordDTO->status)->toBe(JobState::Succeeded);
            expect($jobRecordDTO->finishedAt)->not->toBeNull();
            expect($jobRecordDTO->runtimeMs)->not->toBeNull();
        });

        it('creates dto from failed job record', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'job-uuid',
                'App\\Jobs\\TestJob',
                'default',
            );
            $jobRecord->start('worker-1');
            $jobRecord->fail('RuntimeException', 'Something failed', 'stack trace...');

            $jobRecordDTO = JobRecordDTO::fromJobRecord($jobRecord);

            expect($jobRecordDTO->status)->toBe(JobState::Failed);
            expect($jobRecordDTO->failureClass)->toBe('RuntimeException');
            expect($jobRecordDTO->failureMessage)->toBe('Something failed');
        });
    });

    describe('toArray', static function (): void {
        it('returns array representation', function (): void {
            $jobRecord = JobRecord::create(
                WorkflowId::generate(),
                StepRunId::generate(),
                'job-uuid-456',
                'App\\Jobs\\ProcessOrder',
                'high',
            );

            $jobRecordDTO = JobRecordDTO::fromJobRecord($jobRecord);
            $array = $jobRecordDTO->toArray();

            expect($array)->toBeArray();
            expect($array['job_uuid'])->toBe('job-uuid-456');
            expect($array['job_class'])->toBe('App\\Jobs\\ProcessOrder');
            expect($array['queue'])->toBe('high');
            expect($array['status'])->toBe('dispatched');
        });
    });
});
