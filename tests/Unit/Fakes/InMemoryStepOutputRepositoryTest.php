<?php

declare(strict_types=1);

use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('InMemoryStepOutputRepository', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryStepOutputRepository();
        $this->workflowId = WorkflowId::generate();
    });

    describe('save and find', function (): void {
        it('saves and retrieves an output', function (): void {
            $output = new TestOutput('test-value');

            $this->repository->save($this->workflowId, $output);
            $found = $this->repository->find($this->workflowId, TestOutput::class);

            expect($found)->toBeInstanceOf(TestOutput::class);
            expect($found->value)->toBe('test-value');
        });

        it('returns null when output does not exist', function (): void {
            expect($this->repository->find($this->workflowId, TestOutput::class))->toBeNull();
        });
    });

    describe('has', function (): void {
        it('returns true when output exists', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));

            expect($this->repository->has($this->workflowId, TestOutput::class))->toBeTrue();
        });

        it('returns false when output does not exist', function (): void {
            expect($this->repository->has($this->workflowId, TestOutput::class))->toBeFalse();
        });
    });

    describe('findAllByWorkflowId', function (): void {
        it('returns all outputs for a workflow', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('test'));
            $this->repository->save($this->workflowId, new AnotherOutput(42));

            $outputs = $this->repository->findAllByWorkflowId($this->workflowId);

            expect($outputs)->toHaveCount(2);
        });

        it('returns empty array when no outputs exist', function (): void {
            expect($this->repository->findAllByWorkflowId($this->workflowId))->toBe([]);
        });
    });

    describe('deleteByWorkflowId', function (): void {
        it('deletes all outputs for a workflow', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('test'));
            $this->repository->save($this->workflowId, new AnotherOutput(42));

            $this->repository->deleteByWorkflowId($this->workflowId);

            expect($this->repository->findAllByWorkflowId($this->workflowId))->toBe([]);
        });
    });

    describe('clear', function (): void {
        it('clears all outputs', function (): void {
            $workflowId2 = WorkflowId::generate();
            $this->repository->save($this->workflowId, new TestOutput('test1'));
            $this->repository->save($workflowId2, new TestOutput('test2'));

            $this->repository->clear();

            expect($this->repository->findAllByWorkflowId($this->workflowId))->toBe([]);
            expect($this->repository->findAllByWorkflowId($workflowId2))->toBe([]);
        });
    });

    describe('isolation', function (): void {
        it('isolates outputs between workflows', function (): void {
            $workflowId2 = WorkflowId::generate();

            $this->repository->save($this->workflowId, new TestOutput('workflow1'));
            $this->repository->save($workflowId2, new TestOutput('workflow2'));

            expect($this->repository->find($this->workflowId, TestOutput::class)->value)->toBe('workflow1');
            expect($this->repository->find($workflowId2, TestOutput::class)->value)->toBe('workflow2');
        });
    });
});
