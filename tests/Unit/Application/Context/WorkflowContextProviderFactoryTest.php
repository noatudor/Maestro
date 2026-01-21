<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProvider;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowContextProviderFactory', function (): void {
    beforeEach(function (): void {
        $this->container = Mockery::mock(Container::class);
        $this->factory = new WorkflowContextProviderFactory($this->container);
    });

    it('creates a provider for a workflow', function (): void {
        $workflowId = WorkflowId::generate();
        $workflowDefinition = WorkflowDefinition::create(
            displayName: 'Test Workflow',
            key: DefinitionKey::fromString('test-workflow'),
            version: DefinitionVersion::fromString('1.0.0'),
            steps: StepCollection::empty(),
        );

        $provider = $this->factory->forWorkflow($workflowId, $workflowDefinition);

        expect($provider)->toBeInstanceOf(WorkflowContextProvider::class);
        expect($provider->workflowId()->equals($workflowId))->toBeTrue();
    });

    it('creates distinct providers for different workflows', function (): void {
        $workflowId1 = WorkflowId::generate();
        $workflowId2 = WorkflowId::generate();
        $workflowDefinition = WorkflowDefinition::create(
            displayName: 'Test Workflow',
            key: DefinitionKey::fromString('test-workflow'),
            version: DefinitionVersion::fromString('1.0.0'),
            steps: StepCollection::empty(),
        );

        $provider1 = $this->factory->forWorkflow($workflowId1, $workflowDefinition);
        $provider2 = $this->factory->forWorkflow($workflowId2, $workflowDefinition);

        expect($provider1)->not->toBe($provider2);
        expect($provider1->workflowId()->equals($workflowId1))->toBeTrue();
        expect($provider2->workflowId()->equals($workflowId2))->toBeTrue();
    });
});
