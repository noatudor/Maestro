<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fixtures\Outputs\MergeableTestOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepOutputStore atomic merge', function (): void {
    beforeEach(function (): void {
        $this->workflowId = WorkflowId::generate();
        $this->repository = new InMemoryStepOutputRepository();
        $this->store = new StepOutputStore($this->workflowId, $this->repository);
    });

    it('writes non-mergeable outputs directly', function (): void {
        $output = new TestOutput('test-value');

        $this->store->write($output);

        expect($this->repository->find($this->workflowId, TestOutput::class))
            ->toBeInstanceOf(TestOutput::class)
            ->and($this->repository->find($this->workflowId, TestOutput::class)->value)
            ->toBe('test-value');
    });

    it('uses atomic merge for mergeable outputs', function (): void {
        $output1 = new MergeableTestOutput(['item1']);
        $this->store->write($output1);

        $output2 = new MergeableTestOutput(['item2']);
        $this->store->write($output2);

        $result = $this->repository->find($this->workflowId, MergeableTestOutput::class);

        expect($result)->toBeInstanceOf(MergeableTestOutput::class);
        expect($result->items)->toBe(['item1', 'item2']);
    });

    it('calls saveWithAtomicMerge for mergeable outputs', function (): void {
        $saveWithAtomicMergeCalled = false;

        $repository = new class($this->workflowId, $saveWithAtomicMergeCalled) extends InMemoryStepOutputRepository
        {
            public function __construct(
                private readonly WorkflowId $expectedWorkflowId,
                private bool &$saveWithAtomicMergeCalled,
            ) {
                parent::__construct();
            }

            public function saveWithAtomicMerge(WorkflowId $workflowId, MergeableOutput $output): void
            {
                $this->saveWithAtomicMergeCalled = true;
                parent::saveWithAtomicMerge($workflowId, $output);
            }
        };

        $store = new StepOutputStore($this->workflowId, $repository);
        $output = new MergeableTestOutput(['item1']);

        $store->write($output);

        expect($saveWithAtomicMergeCalled)->toBeTrue();
    });

    it('does not call saveWithAtomicMerge for non-mergeable outputs', function (): void {
        $saveWithAtomicMergeCalled = false;

        $repository = new class($this->workflowId, $saveWithAtomicMergeCalled) extends InMemoryStepOutputRepository
        {
            public function __construct(
                private readonly WorkflowId $expectedWorkflowId,
                private bool &$saveWithAtomicMergeCalled,
            ) {
                parent::__construct();
            }

            public function saveWithAtomicMerge(WorkflowId $workflowId, MergeableOutput $output): void
            {
                $this->saveWithAtomicMergeCalled = true;
                parent::saveWithAtomicMerge($workflowId, $output);
            }
        };

        $store = new StepOutputStore($this->workflowId, $repository);
        $output = new TestOutput('test-value');

        $store->write($output);

        expect($saveWithAtomicMergeCalled)->toBeFalse();
    });
});
