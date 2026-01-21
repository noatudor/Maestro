<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\StepKey;

describe('StepCollection', static function (): void {
    beforeEach(function (): void {
        $this->step1 = SingleJobStepBuilder::create('step-one')
            ->job(TestJob::class)
            ->produces(TestOutput::class)
            ->build();

        $this->step2 = SingleJobStepBuilder::create('step-two')
            ->job(TestJob::class)
            ->build();

        $this->step3 = SingleJobStepBuilder::create('step-three')
            ->job(TestJob::class)
            ->build();

        $this->collection = StepCollection::fromArray([$this->step1, $this->step2, $this->step3]);
    });

    describe('empty', static function (): void {
        it('creates empty collection', function (): void {
            $stepCollection = StepCollection::empty();

            expect($stepCollection->isEmpty())->toBeTrue();
            expect($stepCollection->count())->toBe(0);
        });
    });

    describe('fromArray', static function (): void {
        it('creates collection from array', function (): void {
            expect($this->collection->count())->toBe(3);
        });
    });

    describe('add', static function (): void {
        it('returns new collection with added step', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('new-step')
                ->job(TestJob::class)
                ->build();

            $newCollection = $this->collection->add($singleJobStepDefinition);

            expect($newCollection->count())->toBe(4);
            expect($this->collection->count())->toBe(3);
        });
    });

    describe('first and last', static function (): void {
        it('returns first step', function (): void {
            expect($this->collection->first()->key()->toString())->toBe('step-one');
        });

        it('returns last step', function (): void {
            expect($this->collection->last()->key()->toString())->toBe('step-three');
        });

        it('returns null for empty collection', function (): void {
            $empty = StepCollection::empty();

            expect($empty->first())->toBeNull();
            expect($empty->last())->toBeNull();
        });
    });

    describe('get', static function (): void {
        it('returns step by index', function (): void {
            expect($this->collection->get(0)->key()->toString())->toBe('step-one');
            expect($this->collection->get(1)->key()->toString())->toBe('step-two');
        });

        it('returns null for invalid index', function (): void {
            expect($this->collection->get(99))->toBeNull();
        });
    });

    describe('findByKey', static function (): void {
        it('returns step by key', function (): void {
            $step = $this->collection->findByKey(StepKey::fromString('step-two'));

            expect($step)->not->toBeNull();
            expect($step->key()->toString())->toBe('step-two');
        });

        it('returns null for non-existent key', function (): void {
            $step = $this->collection->findByKey(StepKey::fromString('non-existent'));

            expect($step)->toBeNull();
        });
    });

    describe('hasKey', static function (): void {
        it('returns true for existing key', function (): void {
            expect($this->collection->hasKey(StepKey::fromString('step-one')))->toBeTrue();
        });

        it('returns false for non-existent key', function (): void {
            expect($this->collection->hasKey(StepKey::fromString('non-existent')))->toBeFalse();
        });
    });

    describe('indexOf', static function (): void {
        it('returns index for existing key', function (): void {
            expect($this->collection->indexOf(StepKey::fromString('step-one')))->toBe(0);
            expect($this->collection->indexOf(StepKey::fromString('step-two')))->toBe(1);
        });

        it('returns null for non-existent key', function (): void {
            expect($this->collection->indexOf(StepKey::fromString('non-existent')))->toBeNull();
        });
    });

    describe('getNextStep', static function (): void {
        it('returns next step', function (): void {
            $next = $this->collection->getNextStep(StepKey::fromString('step-one'));

            expect($next->key()->toString())->toBe('step-two');
        });

        it('returns null for last step', function (): void {
            $next = $this->collection->getNextStep(StepKey::fromString('step-three'));

            expect($next)->toBeNull();
        });

        it('returns null for non-existent key', function (): void {
            $next = $this->collection->getNextStep(StepKey::fromString('non-existent'));

            expect($next)->toBeNull();
        });
    });

    describe('isFirstStep and isLastStep', static function (): void {
        it('identifies first step', function (): void {
            expect($this->collection->isFirstStep(StepKey::fromString('step-one')))->toBeTrue();
            expect($this->collection->isFirstStep(StepKey::fromString('step-two')))->toBeFalse();
        });

        it('identifies last step', function (): void {
            expect($this->collection->isLastStep(StepKey::fromString('step-three')))->toBeTrue();
            expect($this->collection->isLastStep(StepKey::fromString('step-one')))->toBeFalse();
        });
    });

    describe('keys', static function (): void {
        it('returns all step keys', function (): void {
            $keys = $this->collection->keys();

            expect($keys)->toHaveCount(3);
            expect($keys[0]->toString())->toBe('step-one');
            expect($keys[1]->toString())->toBe('step-two');
            expect($keys[2]->toString())->toBe('step-three');
        });
    });

    describe('stepsBefore and stepsAfter', static function (): void {
        it('returns steps before given key', function (): void {
            $before = $this->collection->stepsBefore(StepKey::fromString('step-three'));

            expect($before->count())->toBe(2);
            expect($before->first()->key()->toString())->toBe('step-one');
        });

        it('returns empty for first step', function (): void {
            $before = $this->collection->stepsBefore(StepKey::fromString('step-one'));

            expect($before->isEmpty())->toBeTrue();
        });

        it('returns steps after given key', function (): void {
            $after = $this->collection->stepsAfter(StepKey::fromString('step-one'));

            expect($after->count())->toBe(2);
            expect($after->first()->key()->toString())->toBe('step-two');
        });

        it('returns empty for last step', function (): void {
            $after = $this->collection->stepsAfter(StepKey::fromString('step-three'));

            expect($after->isEmpty())->toBeTrue();
        });
    });

    describe('map', static function (): void {
        it('maps steps', function (): void {
            $keys = $this->collection->map(static fn ($step) => $step->key()->toString());

            expect($keys)->toBe(['step-one', 'step-two', 'step-three']);
        });
    });

    describe('filter', static function (): void {
        it('filters steps', function (): void {
            $filtered = $this->collection->filter(static fn ($step) => $step->producesOutput());

            expect($filtered->count())->toBe(1);
            expect($filtered->first()->key()->toString())->toBe('step-one');
        });
    });

    describe('any and every', static function (): void {
        it('checks if any step matches', function (): void {
            expect($this->collection->any(static fn ($step) => $step->producesOutput()))->toBeTrue();
            expect($this->collection->any(static fn ($step) => $step->hasRequirements()))->toBeFalse();
        });

        it('checks if every step matches', function (): void {
            expect($this->collection->every(static fn ($step) => $step->producesOutput()))->toBeFalse();
            expect($this->collection->every(static fn ($step): bool => ! $step->hasRequirements()))->toBeTrue();
        });
    });

    describe('isNotEmpty', static function (): void {
        it('returns true for non-empty collection', function (): void {
            expect($this->collection->isNotEmpty())->toBeTrue();
        });

        it('returns false for empty collection', function (): void {
            expect(StepCollection::empty()->isNotEmpty())->toBeFalse();
        });
    });

    describe('all', static function (): void {
        it('returns all steps', function (): void {
            $all = $this->collection->all();

            expect($all)->toHaveCount(3);
            expect($all[0]->key()->toString())->toBe('step-one');
        });
    });

    describe('stepsAfter for non-existent key', static function (): void {
        it('returns empty for non-existent key', function (): void {
            $after = $this->collection->stepsAfter(StepKey::fromString('non-existent'));

            expect($after->isEmpty())->toBeTrue();
        });
    });

    describe('stepsBefore for non-existent key', static function (): void {
        it('returns empty for non-existent key', function (): void {
            $before = $this->collection->stepsBefore(StepKey::fromString('non-existent'));

            expect($before->isEmpty())->toBeTrue();
        });
    });
});
