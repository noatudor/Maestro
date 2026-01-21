<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepOutputStoreFactory', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryStepOutputRepository();
        $this->factory = new StepOutputStoreFactory($this->repository);
    });

    it('creates a store for a workflow', function (): void {
        $workflowId = WorkflowId::generate();

        $store = $this->factory->forWorkflow($workflowId);

        expect($store)->toBeInstanceOf(StepOutputStore::class);
        expect($store->workflowId()->equals($workflowId))->toBeTrue();
    });

    it('creates distinct stores for different workflows', function (): void {
        $workflowId1 = WorkflowId::generate();
        $workflowId2 = WorkflowId::generate();

        $store1 = $this->factory->forWorkflow($workflowId1);
        $store2 = $this->factory->forWorkflow($workflowId2);

        expect($store1->workflowId()->equals($workflowId1))->toBeTrue();
        expect($store2->workflowId()->equals($workflowId2))->toBeTrue();
        expect($store1->workflowId()->equals($store2->workflowId()))->toBeFalse();
    });
});
