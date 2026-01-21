<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobRecordCollection', static function (): void {
    beforeEach(function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->workflowId = WorkflowId::generate();
        $this->stepRunId = StepRunId::generate();
    });

    afterEach(function (): void {
        CarbonImmutable::setTestNow(null);
    });

    it('creates an empty collection', function (): void {
        $collection = JobRecordCollection::empty();

        expect($collection->isEmpty())->toBeTrue()
            ->and($collection->count())->toBe(0);
    });

    it('creates from array', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');

        $collection = JobRecordCollection::fromArray([$job1, $job2]);

        expect($collection->count())->toBe(2)
            ->and($collection->isNotEmpty())->toBeTrue();
    });

    it('adds a job record', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');

        $collection = JobRecordCollection::empty()->add($job1)->add($job2);

        expect($collection->count())->toBe(2);
    });

    it('filters by dispatched state', function (): void {
        $dispatched = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $running->start();

        $collection = new JobRecordCollection([$dispatched, $running]);

        expect($collection->dispatched()->count())->toBe(1);
    });

    it('filters by running state', function (): void {
        $dispatched = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $running->start();

        $collection = new JobRecordCollection([$dispatched, $running]);

        expect($collection->running()->count())->toBe(1);
    });

    it('filters by succeeded state', function (): void {
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running->start();
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();

        $collection = new JobRecordCollection([$running, $succeeded]);

        expect($collection->succeeded()->count())->toBe(1);
    });

    it('filters by failed state', function (): void {
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running->start();
        $failed = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $failed->start();
        $failed->fail();

        $collection = new JobRecordCollection([$running, $failed]);

        expect($collection->failed()->count())->toBe(1);
    });

    it('filters by terminal state', function (): void {
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running->start();
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();
        $failed = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'default');
        $failed->start();
        $failed->fail();

        $collection = new JobRecordCollection([$running, $succeeded, $failed]);

        expect($collection->terminal()->count())->toBe(2);
    });

    it('filters by specific state', function (): void {
        $dispatched = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $running->start();

        $collection = new JobRecordCollection([$dispatched, $running]);

        expect($collection->byState(JobState::Dispatched)->count())->toBe(1)
            ->and($collection->byState(JobState::Running)->count())->toBe(1);
    });

    it('filters by step run id', function (): void {
        $stepRunId2 = StepRunId::generate();
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job2 = JobRecord::create($this->workflowId, $stepRunId2, 'uuid-2', 'App\\Job', 'default');

        $collection = new JobRecordCollection([$job1, $job2]);

        expect($collection->forStepRun($this->stepRunId)->count())->toBe(1);
    });

    it('finds by job uuid', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');

        $collection = new JobRecordCollection([$job1, $job2]);

        $found = $collection->findByJobUuid('uuid-2');

        expect($found->jobUuid)->toBe('uuid-2');
    });

    it('returns null when job uuid not found', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');

        $collection = new JobRecordCollection([$job1]);

        $found = $collection->findByJobUuid('nonexistent');

        expect($found)->toBeNull();
    });

    it('filters by queue', function (): void {
        $defaultQueue = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $highQueue = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'high');

        $collection = new JobRecordCollection([$defaultQueue, $highQueue]);

        expect($collection->findByQueue('high')->count())->toBe(1);
    });

    it('counts succeeded jobs', function (): void {
        $succeeded1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $succeeded1->start();
        $succeeded1->succeed();
        $succeeded2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $succeeded2->start();
        $succeeded2->succeed();
        $failed = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'default');
        $failed->start();
        $failed->fail();

        $collection = new JobRecordCollection([$succeeded1, $succeeded2, $failed]);

        expect($collection->succeededCount())->toBe(2);
    });

    it('counts failed jobs', function (): void {
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();
        $failed1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $failed1->start();
        $failed1->fail();
        $failed2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'default');
        $failed2->start();
        $failed2->fail();

        $collection = new JobRecordCollection([$succeeded, $failed1, $failed2]);

        expect($collection->failedCount())->toBe(2);
    });

    it('counts terminal jobs', function (): void {
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running->start();
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();
        $failed = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'default');
        $failed->start();
        $failed->fail();

        $collection = new JobRecordCollection([$running, $succeeded, $failed]);

        expect($collection->terminalCount())->toBe(2);
    });

    it('counts in-progress jobs', function (): void {
        $dispatched = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $running->start();
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();

        $collection = new JobRecordCollection([$dispatched, $running, $succeeded]);

        expect($collection->inProgressCount())->toBe(2);
    });

    it('calculates total runtime', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job1->start();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(100));
        $job1->succeed();

        $job2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $job2->start();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(200));
        $job2->succeed();

        $collection = new JobRecordCollection([$job1, $job2]);

        expect($collection->totalRuntimeMs())->toBe(300);
    });

    it('calculates average runtime', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job1->start();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(100));
        $job1->succeed();

        $job2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $job2->start();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(200));
        $job2->succeed();

        $collection = new JobRecordCollection([$job1, $job2]);

        expect($collection->averageRuntimeMs())->toBe(150.0);
    });

    it('returns zero average for empty collection', function (): void {
        $collection = JobRecordCollection::empty();

        expect($collection->averageRuntimeMs())->toBe(0.0);
    });

    it('checks if any have failed', function (): void {
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();
        $failed = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $failed->start();
        $failed->fail();

        $collection = new JobRecordCollection([$succeeded, $failed]);

        expect($collection->hasAnyFailed())->toBeTrue();
    });

    it('checks if all succeeded', function (): void {
        $succeeded1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $succeeded1->start();
        $succeeded1->succeed();
        $succeeded2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $succeeded2->start();
        $succeeded2->succeed();

        $collection = new JobRecordCollection([$succeeded1, $succeeded2]);

        expect($collection->areAllSucceeded())->toBeTrue();
    });

    it('checks if all are terminal', function (): void {
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();
        $failed = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $failed->start();
        $failed->fail();

        $collection = new JobRecordCollection([$succeeded, $failed]);

        expect($collection->areAllTerminal())->toBeTrue()
            ->and($collection->areAllCompleted())->toBeTrue();
    });

    it('counts by queue', function (): void {
        $default1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $default2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $high = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'high');

        $collection = new JobRecordCollection([$default1, $default2, $high]);

        $counts = $collection->countByQueue();

        expect($counts)->toBe(['default' => 2, 'high' => 1]);
    });

    it('counts by status', function (): void {
        $dispatched = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $running = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');
        $running->start();
        $succeeded = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-3', 'App\\Job', 'default');
        $succeeded->start();
        $succeeded->succeed();

        $collection = new JobRecordCollection([$dispatched, $running, $succeeded]);

        $counts = $collection->countByStatus();

        expect($counts)->toBe(['dispatched' => 1, 'running' => 1, 'succeeded' => 1]);
    });

    it('is iterable', function (): void {
        $job1 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-1', 'App\\Job', 'default');
        $job2 = JobRecord::create($this->workflowId, $this->stepRunId, 'uuid-2', 'App\\Job', 'default');

        $collection = new JobRecordCollection([$job1, $job2]);

        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        expect($items)->toHaveCount(2);
    });
});
