<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\FanOutStepBuilder;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('GraphWorkflowCommand', function (): void {
    beforeEach(function (): void {
        $this->registry = new WorkflowDefinitionRegistry();
        $this->app->instance(WorkflowDefinitionRegistry::class, $this->registry);
    });

    it('fails with invalid definition key', function (): void {
        $this->artisan('maestro:graph', ['definition' => 'invalid key with spaces'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid definition key');
    });

    it('fails when definition is not found', function (): void {
        $this->artisan('maestro:graph', ['definition' => 'non-existent'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Definition not found');
    });

    it('outputs text format by default', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('step-one')
            ->displayName('First Step')
            ->job(TestJob::class)
            ->produces(TestOutput::class)
            ->build();

        $step2 = SingleJobStepBuilder::create('step-two')
            ->displayName('Second Step')
            ->job(TestJob::class)
            ->requires(TestOutput::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('graph-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Graph Workflow',
            StepCollection::fromArray([$singleJobStepDefinition, $step2]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', ['definition' => 'graph-workflow'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Workflow: graph-workflow v1.0.0')
            ->expectsOutputToContain('step-one')
            ->expectsOutputToContain('step-two')
            ->expectsOutputToContain('Legend');
    });

    it('outputs mermaid format', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('mermaid-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Mermaid Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', [
            'definition' => 'mermaid-workflow',
            '--format' => 'mermaid',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('```mermaid')
            ->expectsOutputToContain('flowchart TD')
            ->expectsOutputToContain('test_step');
    });

    it('outputs dot format', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('dot-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'DOT Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', [
            'definition' => 'dot-workflow',
            '--format' => 'dot',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('digraph workflow')
            ->expectsOutputToContain('rankdir=TB')
            ->expectsOutputToContain('test_step');
    });

    it('fails with invalid format', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Test Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', [
            'definition' => 'test-workflow',
            '--format' => 'invalid',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid format');
    });

    it('shows fan-out steps with different indicator', function (): void {
        $fanOutStepDefinition = FanOutStepBuilder::create('fan-out-step')
            ->displayName('Fan Out Step')
            ->job(TestJob::class)
            ->iterateOver(static fn (): array => [1, 2, 3])
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('fanout-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Fan-Out Workflow',
            StepCollection::fromArray([$fanOutStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', ['definition' => 'fanout-workflow'])
            ->assertExitCode(0)
            ->expectsOutputToContain('FAN-OUT');
    });

    it('shows step dependencies in text format', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('producer')
            ->displayName('Producer Step')
            ->job(TestJob::class)
            ->produces(TestOutput::class)
            ->build();

        $step2 = SingleJobStepBuilder::create('consumer')
            ->displayName('Consumer Step')
            ->job(TestJob::class)
            ->requires(TestOutput::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('dependency-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Dependency Workflow',
            StepCollection::fromArray([$singleJobStepDefinition, $step2]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', ['definition' => 'dependency-workflow'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Produces')
            ->expectsOutputToContain('Requires');
    });

    it('shows empty steps message for workflow with no steps', function (): void {
        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('empty-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Empty Workflow',
            StepCollection::empty(),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', ['definition' => 'empty-workflow'])
            ->assertExitCode(0)
            ->expectsOutputToContain('No steps defined');
    });

    it('shows connection between steps in mermaid format', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('first')
            ->displayName('First')
            ->job(TestJob::class)
            ->build();

        $step2 = SingleJobStepBuilder::create('second')
            ->displayName('Second')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('connected-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Connected Workflow',
            StepCollection::fromArray([$singleJobStepDefinition, $step2]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:graph', [
            'definition' => 'connected-workflow',
            '--format' => 'mermaid',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('first --> second');
    });
});
