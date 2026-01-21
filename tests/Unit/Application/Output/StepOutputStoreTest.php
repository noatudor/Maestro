<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Exceptions\MissingRequiredOutputException;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\MergeableTestOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepOutputStore', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryStepOutputRepository();
        $this->workflowId = WorkflowId::generate();
        $this->store = new StepOutputStore($this->workflowId, $this->repository);
    });

    describe('read', function (): void {
        it('reads an existing output', function (): void {
            $output = new TestOutput('test-value');
            $this->repository->save($this->workflowId, $output);

            $result = $this->store->read(TestOutput::class);

            expect($result)->toBeInstanceOf(TestOutput::class);
            expect($result->value)->toBe('test-value');
        });

        it('throws when output does not exist', function (): void {
            $this->store->read(TestOutput::class);
        })->throws(MissingRequiredOutputException::class);

        it('reads different output types independently', function (): void {
            $testOutput = new TestOutput('test');
            $anotherOutput = new AnotherOutput(42);
            $this->repository->save($this->workflowId, $testOutput);
            $this->repository->save($this->workflowId, $anotherOutput);

            $readTest = $this->store->read(TestOutput::class);
            $readAnother = $this->store->read(AnotherOutput::class);

            expect($readTest->value)->toBe('test');
            expect($readAnother->count)->toBe(42);
        });
    });

    describe('has', function (): void {
        it('returns true when output exists', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));

            expect($this->store->has(TestOutput::class))->toBeTrue();
        });

        it('returns false when output does not exist', function (): void {
            expect($this->store->has(TestOutput::class))->toBeFalse();
        });
    });

    describe('write', function (): void {
        it('writes an output', function (): void {
            $output = new TestOutput('test-value');

            $this->store->write($output);

            expect($this->store->has(TestOutput::class))->toBeTrue();
            expect($this->store->read(TestOutput::class)->value)->toBe('test-value');
        });

        it('overwrites non-mergeable output', function (): void {
            $this->store->write(new TestOutput('first'));
            $this->store->write(new TestOutput('second'));

            $result = $this->store->read(TestOutput::class);

            expect($result->value)->toBe('second');
        });

        it('merges mergeable output with existing', function (): void {
            $first = new MergeableTestOutput(['item1', 'item2']);
            $second = new MergeableTestOutput(['item3', 'item4']);

            $this->store->write($first);
            $this->store->write($second);

            $result = $this->store->read(MergeableTestOutput::class);

            expect($result->items)->toBe(['item1', 'item2', 'item3', 'item4']);
        });

        it('writes mergeable output without existing as normal write', function (): void {
            $output = new MergeableTestOutput(['item1']);

            $this->store->write($output);

            $result = $this->store->read(MergeableTestOutput::class);
            expect($result->items)->toBe(['item1']);
        });
    });

    describe('all', function (): void {
        it('returns all outputs for the workflow', function (): void {
            $this->store->write(new TestOutput('test'));
            $this->store->write(new AnotherOutput(42));

            $all = $this->store->all();

            expect($all)->toHaveCount(2);
        });

        it('returns empty array when no outputs exist', function (): void {
            expect($this->store->all())->toBe([]);
        });
    });

    describe('workflowId', function (): void {
        it('returns the workflow id', function (): void {
            expect($this->store->workflowId()->equals($this->workflowId))->toBeTrue();
        });
    });

    describe('isolation', function (): void {
        it('isolates outputs between different workflows', function (): void {
            $otherWorkflowId = WorkflowId::generate();
            $otherStore = new StepOutputStore($otherWorkflowId, $this->repository);

            $this->store->write(new TestOutput('workflow1'));
            $otherStore->write(new TestOutput('workflow2'));

            expect($this->store->read(TestOutput::class)->value)->toBe('workflow1');
            expect($otherStore->read(TestOutput::class)->value)->toBe('workflow2');
        });
    });
});
