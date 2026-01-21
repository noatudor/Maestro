<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Job\DefaultIdempotencyKeyGenerator;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('DefaultIdempotencyKeyGenerator', function (): void {
    beforeEach(function (): void {
        $this->generator = new DefaultIdempotencyKeyGenerator();
    });

    it('generates key based on job uuid', function (): void {
        $job = new TestOrchestratedJob(
            WorkflowId::generate(),
            StepRunId::generate(),
            'unique-job-uuid-123',
        );

        $key = $this->generator->generate($job);

        expect($key)->toBe('maestro:job:unique-job-uuid-123');
    });

    it('generates same key for same job', function (): void {
        $job = new TestOrchestratedJob(
            WorkflowId::generate(),
            StepRunId::generate(),
            'deterministic-uuid',
        );

        $key1 = $this->generator->generate($job);
        $key2 = $this->generator->generate($job);

        expect($key1)->toBe($key2);
    });

    it('generates different keys for different jobs', function (): void {
        $job1 = new TestOrchestratedJob(
            WorkflowId::generate(),
            StepRunId::generate(),
            'job-uuid-1',
        );

        $job2 = new TestOrchestratedJob(
            WorkflowId::generate(),
            StepRunId::generate(),
            'job-uuid-2',
        );

        $key1 = $this->generator->generate($job1);
        $key2 = $this->generator->generate($job2);

        expect($key1)->not->toBe($key2);
    });
});
