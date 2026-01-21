<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('AbstractCollection', static function (): void {
    beforeEach(function (): void {
        $this->workflowId = WorkflowId::generate();
    });

    describe('first', static function (): void {
        it('returns first item without callback', function (): void {
            $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

            $stepRunCollection = StepRunCollection::fromArray([$stepRun, $step2]);

            expect($stepRunCollection->first())->toBe($stepRun);
        });

        it('returns null for empty collection', function (): void {
            $stepRunCollection = StepRunCollection::empty();

            expect($stepRunCollection->first())->toBeNull();
        });

        it('returns first item matching callback', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));
            $step2->start();

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->first(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBe($step2);
        });

        it('returns null when no item matches callback', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));

            $stepRunCollection = StepRunCollection::fromArray([$step1]);

            $result = $stepRunCollection->first(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBeNull();
        });
    });

    describe('last', static function (): void {
        it('returns last item without callback', function (): void {
            $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));
            $step3 = StepRun::create($this->workflowId, StepKey::fromString('step-3'));

            $stepRunCollection = StepRunCollection::fromArray([$stepRun, $step2, $step3]);

            expect($stepRunCollection->last())->toBe($step3);
        });

        it('returns null for empty collection', function (): void {
            $stepRunCollection = StepRunCollection::empty();

            expect($stepRunCollection->last())->toBeNull();
        });

        it('returns last item matching callback', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step1->start();
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));
            $step3 = StepRun::create($this->workflowId, StepKey::fromString('step-3'));
            $step3->start();

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2, $step3]);

            $result = $stepRunCollection->last(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBe($step3);
        });

        it('returns null when no item matches callback', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));

            $stepRunCollection = StepRunCollection::fromArray([$step1]);

            $result = $stepRunCollection->last(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBeNull();
        });
    });

    describe('map', static function (): void {
        it('maps items to new values', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->map(static fn (StepRun $stepRun): string => $stepRun->stepKey->toString());

            expect($result)->toBe(['step-1', 'step-2']);
        });

        it('returns empty array for empty collection', function (): void {
            $stepRunCollection = StepRunCollection::empty();

            $result = $stepRunCollection->map(static fn (StepRun $stepRun): string => $stepRun->stepKey->toString());

            expect($result)->toBe([]);
        });
    });

    describe('any', static function (): void {
        it('returns true when any item matches', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));
            $step2->start();

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->any(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBeTrue();
        });

        it('returns false when no item matches', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->any(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBeFalse();
        });
    });

    describe('every', static function (): void {
        it('returns true when all items match', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->every(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Pending);

            expect($result)->toBeTrue();
        });

        it('returns false when not all items match', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));
            $step2->start();

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->every(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Pending);

            expect($result)->toBeFalse();
        });
    });

    describe('none', static function (): void {
        it('returns true when no item matches', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->none(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBeTrue();
        });

        it('returns false when any item matches', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));
            $step2->start();

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2]);

            $result = $stepRunCollection->none(static fn (StepRun $stepRun): bool => $stepRun->status() === StepState::Running);

            expect($result)->toBeFalse();
        });
    });

    describe('sum', static function (): void {
        it('sums values from callback', function (): void {
            $step1 = StepRun::create($this->workflowId, StepKey::fromString('step-1'), totalJobCount: 3);
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'), totalJobCount: 5);
            $step3 = StepRun::create($this->workflowId, StepKey::fromString('step-3'), totalJobCount: 2);

            $stepRunCollection = StepRunCollection::fromArray([$step1, $step2, $step3]);

            $result = $stepRunCollection->sum(static fn (StepRun $stepRun): int => $stepRun->totalJobCount);

            expect($result)->toBe(10);
        });

        it('returns 0 for empty collection', function (): void {
            $stepRunCollection = StepRunCollection::empty();

            $result = $stepRunCollection->sum(static fn (StepRun $stepRun): int => $stepRun->totalJobCount);

            expect($result)->toBe(0);
        });
    });

    describe('all and values', static function (): void {
        it('returns all items', function (): void {
            $stepRun = StepRun::create($this->workflowId, StepKey::fromString('step-1'));
            $step2 = StepRun::create($this->workflowId, StepKey::fromString('step-2'));

            $stepRunCollection = StepRunCollection::fromArray([$stepRun, $step2]);

            $all = $stepRunCollection->all();
            $values = $stepRunCollection->values();

            expect($all)->toBe([$stepRun, $step2]);
            expect($values)->toBe([$stepRun, $step2]);
        });
    });
});
