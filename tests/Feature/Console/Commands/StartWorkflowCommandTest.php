<?php

declare(strict_types=1);

use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StartWorkflowCommand', function (): void {
    beforeEach(function (): void {
        $this->registry = new WorkflowDefinitionRegistry();
        $this->app->instance(WorkflowDefinitionRegistry::class, $this->registry);
    });

    it('fails when definition key is invalid', function (): void {
        $this->artisan('maestro:start', ['definition' => 'invalid key with spaces'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid definition key');
    });

    it('fails when definition is not found', function (): void {
        $this->artisan('maestro:start', ['definition' => 'non-existent-workflow'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Definition not found');
    });

    it('starts a workflow successfully', function (): void {
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::fromString('1.0.0');

        $workflowDefinition = WorkflowDefinition::create(
            $definitionKey,
            $definitionVersion,
            'Test Workflow',
            StepCollection::empty(),
        );

        $this->registry->register($workflowDefinition);

        $workflowId = WorkflowId::generate();
        $workflowInstance = WorkflowInstance::create(
            $definitionKey,
            $definitionVersion,
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));

        $mock = Mockery::mock(WorkflowManager::class);
        $mock->shouldReceive('startWorkflow')
            ->once()
            ->with(
                Mockery::on(static fn ($key): bool => $key->value === 'test-workflow'),
                Mockery::any(),
            )
            ->andReturn($workflowInstance);

        $this->app->instance(WorkflowManager::class, $mock);

        $this->artisan('maestro:start', ['definition' => 'test-workflow'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Workflow started successfully');
    });

    it('starts a workflow with custom ID', function (): void {
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::fromString('1.0.0');

        $workflowDefinition = WorkflowDefinition::create(
            $definitionKey,
            $definitionVersion,
            'Test Workflow',
            StepCollection::empty(),
        );

        $this->registry->register($workflowDefinition);

        $customId = 'custom-workflow-id';
        $workflowInstance = WorkflowInstance::create(
            $definitionKey,
            $definitionVersion,
            WorkflowId::fromString($customId),
        );

        $mock = Mockery::mock(WorkflowManager::class);
        $mock->shouldReceive('startWorkflow')
            ->once()
            ->with(
                Mockery::on(static fn ($key): bool => $key->value === 'test-workflow'),
                Mockery::on(static fn ($id): bool => $id->value === $customId),
            )
            ->andReturn($workflowInstance);

        $this->app->instance(WorkflowManager::class, $mock);

        $this->artisan('maestro:start', [
            'definition' => 'test-workflow',
            '--id' => $customId,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Workflow started successfully');
    });

    it('lists available definitions with --list option', function (): void {
        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('workflow-one'),
            DefinitionVersion::fromString('1.0.0'),
            'First Workflow',
            StepCollection::empty(),
        );

        $definition2 = WorkflowDefinition::create(
            DefinitionKey::fromString('workflow-two'),
            DefinitionVersion::fromString('2.0.0'),
            'Second Workflow',
            StepCollection::empty(),
        );

        $this->registry->register($workflowDefinition);
        $this->registry->register($definition2);

        $this->artisan('maestro:start', [
            'definition' => 'dummy',
            '--list' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('workflow-one')
            ->expectsOutputToContain('workflow-two');
    });

    it('handles empty registry for --list option', function (): void {
        $this->artisan('maestro:start', [
            'definition' => 'dummy',
            '--list' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('No workflow definitions registered');
    });
});
