<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\WorkflowHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepOutputRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentWorkflowRepository;
use Maestro\Workflow\Infrastructure\Serialization\PhpOutputSerializer;
use Maestro\Workflow\Tests\Fixtures\Outputs\MergeableTestOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('Atomic output merge with real database', function (): void {
    beforeEach(function (): void {
        $this->serializer = new PhpOutputSerializer();
        $this->repository = new EloquentStepOutputRepository(
            $this->serializer,
            DB::connection(),
        );

        $workflowHydrator = new WorkflowHydrator();
        $workflowRepository = new EloquentWorkflowRepository(
            $workflowHydrator,
            DB::connection(),
        );

        $workflowInstance = WorkflowInstance::create(
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
        );
        $workflowRepository->save($workflowInstance);

        $this->workflowId = $workflowInstance->id;
    });

    describe('non-mergeable outputs', function (): void {
        it('saves non-mergeable output directly', function (): void {
            $output = new TestOutput('test-value');

            $this->repository->save($this->workflowId, $output);

            $retrieved = $this->repository->find($this->workflowId, TestOutput::class);
            expect($retrieved)->toBeInstanceOf(TestOutput::class);
            expect($retrieved->value)->toBe('test-value');
        });

        it('replaces non-mergeable output on subsequent save', function (): void {
            $output1 = new TestOutput('value-1');
            $this->repository->save($this->workflowId, $output1);

            $output2 = new TestOutput('value-2');
            $this->repository->save($this->workflowId, $output2);

            $retrieved = $this->repository->find($this->workflowId, TestOutput::class);
            expect($retrieved->value)->toBe('value-2');
        });
    });

    describe('mergeable outputs', function (): void {
        it('saves mergeable output atomically', function (): void {
            $output = new MergeableTestOutput(['item1']);

            $this->repository->saveWithAtomicMerge($this->workflowId, $output);

            $retrieved = $this->repository->find($this->workflowId, MergeableTestOutput::class);
            expect($retrieved)->toBeInstanceOf(MergeableTestOutput::class);
            expect($retrieved->items)->toBe(['item1']);
        });

        it('merges outputs atomically', function (): void {
            $output1 = new MergeableTestOutput(['item1']);
            $this->repository->saveWithAtomicMerge($this->workflowId, $output1);

            $output2 = new MergeableTestOutput(['item2']);
            $this->repository->saveWithAtomicMerge($this->workflowId, $output2);

            $retrieved = $this->repository->find($this->workflowId, MergeableTestOutput::class);
            expect($retrieved->items)->toBe(['item1', 'item2']);
        });

        it('merges multiple outputs sequentially', function (): void {
            $items = ['item1', 'item2', 'item3', 'item4', 'item5'];

            foreach ($items as $item) {
                $output = new MergeableTestOutput([$item]);
                $this->repository->saveWithAtomicMerge($this->workflowId, $output);
            }

            $retrieved = $this->repository->find($this->workflowId, MergeableTestOutput::class);
            expect($retrieved->items)->toBe($items);
        });
    });

    describe('findForUpdate', function (): void {
        it('acquires row lock when finding for update', function (): void {
            $output = new MergeableTestOutput(['item1']);
            $this->repository->save($this->workflowId, $output);

            DB::beginTransaction();

            try {
                $lockedOutput = $this->repository->findForUpdate(
                    $this->workflowId,
                    MergeableTestOutput::class,
                );

                expect($lockedOutput)->toBeInstanceOf(MergeableTestOutput::class);
                expect($lockedOutput->items)->toBe(['item1']);
            } finally {
                DB::rollBack();
            }
        });

        it('returns null when output does not exist', function (): void {
            DB::beginTransaction();

            try {
                $lockedOutput = $this->repository->findForUpdate(
                    $this->workflowId,
                    MergeableTestOutput::class,
                );

                expect($lockedOutput)->toBeNull();
            } finally {
                DB::rollBack();
            }
        });
    });

    describe('data isolation', function (): void {
        it('keeps outputs isolated between workflows', function (): void {
            $workflowHydrator = new WorkflowHydrator();
            $workflowRepository = new EloquentWorkflowRepository(
                $workflowHydrator,
                DB::connection(),
            );

            $workflowInstance = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow-1'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $workflowRepository->save($workflowInstance);

            $workflow2 = WorkflowInstance::create(
                definitionKey: DefinitionKey::fromString('test-workflow-2'),
                definitionVersion: DefinitionVersion::fromString('1.0.0'),
            );
            $workflowRepository->save($workflow2);

            $output1 = new MergeableTestOutput(['workflow1-item']);
            $this->repository->saveWithAtomicMerge($workflowInstance->id, $output1);

            $output2 = new MergeableTestOutput(['workflow2-item']);
            $this->repository->saveWithAtomicMerge($workflow2->id, $output2);

            $retrieved1 = $this->repository->find($workflowInstance->id, MergeableTestOutput::class);
            $retrieved2 = $this->repository->find($workflow2->id, MergeableTestOutput::class);

            expect($retrieved1->items)->toBe(['workflow1-item']);
            expect($retrieved2->items)->toBe(['workflow2-item']);
        });

        it('keeps different output types isolated', function (): void {
            $mergeableOutput = new MergeableTestOutput(['merged-item']);
            $testOutput = new TestOutput('test-value');

            $this->repository->save($this->workflowId, $mergeableOutput);
            $this->repository->save($this->workflowId, $testOutput);

            $retrievedMergeable = $this->repository->find($this->workflowId, MergeableTestOutput::class);
            $retrievedTest = $this->repository->find($this->workflowId, TestOutput::class);

            expect($retrievedMergeable->items)->toBe(['merged-item']);
            expect($retrievedTest->value)->toBe('test-value');
        });
    });
});
