<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepRunCollection', static function (): void {
    beforeEach(function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $this->workflowId = WorkflowId::generate();
    });

    afterEach(function (): void {
        CarbonImmutable::setTestNow(null);
    });

    it('creates an empty collection', function (): void {
        $collection = StepRunCollection::empty();

        expect($collection->isEmpty())->toBeTrue()
            ->and($collection->count())->toBe(0);
    });

    it('creates from array', function (): void {
        $stepRun1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $stepRun2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $collection = StepRunCollection::fromArray([$stepRun1, $stepRun2]);

        expect($collection->count())->toBe(2)
            ->and($collection->isNotEmpty())->toBeTrue();
    });

    it('adds a step run', function (): void {
        $stepRun1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $stepRun2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $collection = StepRunCollection::empty()->add($stepRun1)->add($stepRun2);

        expect($collection->count())->toBe(2);
    });

    it('filters by pending state', function (): void {
        $pending = StepRun::create($this->workflowId, StepKey::fromString('pending'));
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();

        $collection = new StepRunCollection([$pending, $running]);

        expect($collection->pending()->count())->toBe(1)
            ->and($collection->pending()->first()->stepKey->value)->toBe('pending');
    });

    it('filters by running state', function (): void {
        $pending = StepRun::create($this->workflowId, StepKey::fromString('pending'));
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();

        $collection = new StepRunCollection([$pending, $running]);

        expect($collection->running()->count())->toBe(1)
            ->and($collection->running()->first()->stepKey->value)->toBe('running');
    });

    it('filters by succeeded state', function (): void {
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();
        $succeeded = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $succeeded->start();
        $succeeded->succeed();

        $collection = new StepRunCollection([$running, $succeeded]);

        expect($collection->succeeded()->count())->toBe(1)
            ->and($collection->succeeded()->first()->stepKey->value)->toBe('succeeded');
    });

    it('filters by failed state', function (): void {
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$running, $failed]);

        expect($collection->failed()->count())->toBe(1)
            ->and($collection->failed()->first()->stepKey->value)->toBe('failed');
    });

    it('filters by terminal state', function (): void {
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();
        $succeeded = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $succeeded->start();
        $succeeded->succeed();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$running, $succeeded, $failed]);

        expect($collection->terminal()->count())->toBe(2);
    });

    it('filters by specific state', function (): void {
        $pending = StepRun::create($this->workflowId, StepKey::fromString('pending'));
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();

        $collection = new StepRunCollection([$pending, $running]);

        expect($collection->byState(StepState::Pending)->count())->toBe(1)
            ->and($collection->byState(StepState::Running)->count())->toBe(1);
    });

    it('finds by step key', function (): void {
        $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $collection = new StepRunCollection([$step1, $step2]);

        $found = $collection->findByKey(StepKey::fromString('step-2'));

        expect($found)->toBe($step2);
    });

    it('returns null when step key not found', function (): void {
        $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));

        $collection = new StepRunCollection([$step1]);

        $found = $collection->findByKey(StepKey::fromString('nonexistent'));

        expect($found)->toBeNull();
    });

    it('finds latest by step key', function (): void {
        $step1Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 1);
        $step1Attempt2 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 2);
        $step1Attempt3 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 3);

        $collection = new StepRunCollection([$step1Attempt1, $step1Attempt2, $step1Attempt3]);

        $latest = $collection->findLatestByKey(StepKey::fromString('step-1'));

        expect($latest->attempt)->toBe(3);
    });

    it('filters by attempt', function (): void {
        $step1Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 1);
        $step2Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), attempt: 1);
        $step1Attempt2 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 2);

        $collection = new StepRunCollection([$step1Attempt1, $step2Attempt1, $step1Attempt2]);

        expect($collection->forAttempt(1)->count())->toBe(2)
            ->and($collection->forAttempt(2)->count())->toBe(1);
    });

    it('gets latest attempts for each step', function (): void {
        $step1Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 1);
        $step1Attempt2 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 2);
        $step2Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), attempt: 1);

        $collection = new StepRunCollection([$step1Attempt1, $step1Attempt2, $step2Attempt1]);

        $latest = $collection->latestAttempts();

        expect($latest->count())->toBe(2)
            ->and($latest->findByKey(StepKey::fromString('step-1'))->attempt)->toBe(2)
            ->and($latest->findByKey(StepKey::fromString('step-2'))->attempt)->toBe(1);
    });

    it('calculates total job count', function (): void {
        $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), totalJobCount: 3);
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), totalJobCount: 5);

        $collection = new StepRunCollection([$step1, $step2]);

        expect($collection->totalJobCount())->toBe(8);
    });

    it('calculates total failed job count', function (): void {
        $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), totalJobCount: 3);
        $step1->recordJobFailure();
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), totalJobCount: 5);
        $step2->recordJobFailure();
        $step2->recordJobFailure();

        $collection = new StepRunCollection([$step1, $step2]);

        expect($collection->totalFailedJobCount())->toBe(3);
    });

    it('checks if any have failed', function (): void {
        $succeeded = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $succeeded->start();
        $succeeded->succeed();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$succeeded, $failed]);

        expect($collection->hasAnyFailed())->toBeTrue();
    });

    it('checks if all succeeded', function (): void {
        $succeeded1 = StepRun::create($this->workflowId, StepKey::fromString('succeeded-1'));
        $succeeded1->start();
        $succeeded1->succeed();
        $succeeded2 = StepRun::create($this->workflowId, StepKey::fromString('succeeded-2'));
        $succeeded2->start();
        $succeeded2->succeed();

        $collection = new StepRunCollection([$succeeded1, $succeeded2]);

        expect($collection->areAllSucceeded())->toBeTrue();
    });

    it('checks if all are terminal', function (): void {
        $succeeded = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $succeeded->start();
        $succeeded->succeed();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$succeeded, $failed]);

        expect($collection->areAllTerminal())->toBeTrue();
    });

    it('is iterable', function (): void {
        $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $collection = new StepRunCollection([$step1, $step2]);

        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        expect($items)->toHaveCount(2);
    });
});
