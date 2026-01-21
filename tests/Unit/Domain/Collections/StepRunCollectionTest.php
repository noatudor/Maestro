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
        CarbonImmutable::setTestNow();
    });

    it('creates an empty collection', function (): void {
        $stepRunCollection = StepRunCollection::empty();

        expect($stepRunCollection->isEmpty())->toBeTrue()
            ->and($stepRunCollection->count())->toBe(0);
    });

    it('creates from array', function (): void {
        $stepRun1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $stepRun2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $stepRunCollection = StepRunCollection::fromArray([$stepRun1, $stepRun2]);

        expect($stepRunCollection->count())->toBe(2)
            ->and($stepRunCollection->isNotEmpty())->toBeTrue();
    });

    it('adds a step run', function (): void {
        $stepRun1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $stepRun2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $stepRunCollection = StepRunCollection::empty()->add($stepRun1)->add($stepRun2);

        expect($stepRunCollection->count())->toBe(2);
    });

    it('filters by pending state', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('pending'));
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();

        $collection = new StepRunCollection([$stepRun, $running]);

        expect($collection->pending()->count())->toBe(1)
            ->and($collection->pending()->first()->stepKey->value)->toBe('pending');
    });

    it('filters by running state', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('pending'));
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();

        $collection = new StepRunCollection([$stepRun, $running]);

        expect($collection->running()->count())->toBe(1)
            ->and($collection->running()->first()->stepKey->value)->toBe('running');
    });

    it('filters by succeeded state', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $stepRun->start();
        $succeeded = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $succeeded->start();
        $succeeded->succeed();

        $collection = new StepRunCollection([$stepRun, $succeeded]);

        expect($collection->succeeded()->count())->toBe(1)
            ->and($collection->succeeded()->first()->stepKey->value)->toBe('succeeded');
    });

    it('filters by failed state', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $stepRun->start();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$stepRun, $failed]);

        expect($collection->failed()->count())->toBe(1)
            ->and($collection->failed()->first()->stepKey->value)->toBe('failed');
    });

    it('filters by terminal state', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $stepRun->start();
        $succeeded = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $succeeded->start();
        $succeeded->succeed();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$stepRun, $succeeded, $failed]);

        expect($collection->terminal()->count())->toBe(2);
    });

    it('filters by specific state', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('pending'));
        $running = StepRun::create($this->workflowId, StepKey::fromString('running'));
        $running->start();

        $collection = new StepRunCollection([$stepRun, $running]);

        expect($collection->byState(StepState::Pending)->count())->toBe(1)
            ->and($collection->byState(StepState::Running)->count())->toBe(1);
    });

    it('finds by step key', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $collection = new StepRunCollection([$stepRun, $step2]);

        $found = $collection->findByKey(StepKey::fromString('step-2'));

        expect($found)->toBe($step2);
    });

    it('returns null when step key not found', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'));

        $collection = new StepRunCollection([$stepRun]);

        $found = $collection->findByKey(StepKey::fromString('nonexistent'));

        expect($found)->toBeNull();
    });

    it('finds latest by step key', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 1);
        $step1Attempt2 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 2);
        $step1Attempt3 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 3);

        $collection = new StepRunCollection([$stepRun, $step1Attempt2, $step1Attempt3]);

        $latest = $collection->findLatestByKey(StepKey::fromString('step-1'));

        expect($latest->attempt)->toBe(3);
    });

    it('filters by attempt', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 1);
        $step2Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), attempt: 1);
        $step1Attempt2 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 2);

        $collection = new StepRunCollection([$stepRun, $step2Attempt1, $step1Attempt2]);

        expect($collection->forAttempt(1)->count())->toBe(2)
            ->and($collection->forAttempt(2)->count())->toBe(1);
    });

    it('gets latest attempts for each step', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 1);
        $step1Attempt2 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), attempt: 2);
        $step2Attempt1 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), attempt: 1);

        $collection = new StepRunCollection([$stepRun, $step1Attempt2, $step2Attempt1]);

        $stepRunCollection = $collection->latestAttempts();

        expect($stepRunCollection->count())->toBe(2)
            ->and($stepRunCollection->findByKey(StepKey::fromString('step-1'))->attempt)->toBe(2)
            ->and($stepRunCollection->findByKey(StepKey::fromString('step-2'))->attempt)->toBe(1);
    });

    it('calculates total job count', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'), totalJobCount: 3);
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), totalJobCount: 5);

        $collection = new StepRunCollection([$stepRun, $step2]);

        expect($collection->totalJobCount())->toBe(8);
    });

    it('calculates total failed job count', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'), totalJobCount: 3);
        $stepRun->recordJobFailure();
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), totalJobCount: 5);
        $step2->recordJobFailure();
        $step2->recordJobFailure();

        $collection = new StepRunCollection([$stepRun, $step2]);

        expect($collection->totalFailedJobCount())->toBe(3);
    });

    it('checks if any have failed', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $stepRun->start();
        $stepRun->succeed();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$stepRun, $failed]);

        expect($collection->hasAnyFailed())->toBeTrue();
    });

    it('checks if all succeeded', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('succeeded-1'));
        $stepRun->start();
        $stepRun->succeed();
        $succeeded2 = StepRun::create($this->workflowId, StepKey::fromString('succeeded-2'));
        $succeeded2->start();
        $succeeded2->succeed();

        $collection = new StepRunCollection([$stepRun, $succeeded2]);

        expect($collection->areAllSucceeded())->toBeTrue();
    });

    it('checks if all are terminal', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('succeeded'));
        $stepRun->start();
        $stepRun->succeed();
        $failed = StepRun::create($this->workflowId, StepKey::fromString('failed'));
        $failed->start();
        $failed->fail();

        $collection = new StepRunCollection([$stepRun, $failed]);

        expect($collection->areAllTerminal())->toBeTrue();
    });

    it('is iterable', function (): void {
        $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
        $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

        $collection = new StepRunCollection([$stepRun, $step2]);

        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        expect($items)->toHaveCount(2);
    });
});
