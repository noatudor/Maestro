<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('ValidateDefinitionsCommand', function (): void {
    beforeEach(function (): void {
        $this->registry = new WorkflowDefinitionRegistry();
        $this->app->instance(WorkflowDefinitionRegistry::class, $this->registry);
    });

    it('shows no definitions message when registry is empty', function (): void {
        $this->artisan('maestro:validate')
            ->assertExitCode(0)
            ->expectsOutputToContain('No workflow definitions registered');
    });

    it('validates a single valid definition', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job(TestJob::class)
            ->produces(TestOutput::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('valid-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Valid Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        expect($this->registry->countKeys())->toBe(1);

        $this->artisan('maestro:validate')
            ->assertExitCode(0)
            ->expectsOutputToContain('OK');
    });

    it('validates a specific definition by key', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('specific-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Specific Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:validate', ['definition' => 'specific-workflow'])
            ->assertExitCode(0)
            ->expectsOutputToContain('OK')
            ->expectsOutputToContain('specific-workflow');
    });

    it('fails with invalid definition key', function (): void {
        $this->artisan('maestro:validate', ['definition' => 'invalid key with spaces'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid definition key');
    });

    it('fails when definition is not found', function (): void {
        $this->artisan('maestro:validate', ['definition' => 'non-existent'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Definition not found');
    });

    it('detects empty workflow definition', function (): void {
        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('empty-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Empty Workflow',
            StepCollection::empty(),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:validate', ['--skip-class-check' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('EMPTY_WORKFLOW');
    });

    it('detects missing required output', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('dependent-step')
            ->displayName('Dependent Step')
            ->job(TestJob::class)
            ->requires(TestOutput::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('invalid-deps'),
            DefinitionVersion::fromString('1.0.0'),
            'Invalid Dependencies Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:validate', ['--skip-class-check' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('MISSING_REQUIRED_OUTPUT');
    });

    it('validates all versions when --all-versions flag is set', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('versioned-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Versioned Workflow v1',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $v2 = WorkflowDefinition::create(
            DefinitionKey::fromString('versioned-workflow'),
            DefinitionVersion::fromString('2.0.0'),
            'Versioned Workflow v2',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);
        $this->registry->register($v2);

        $this->artisan('maestro:validate', ['--all-versions' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('all versions')
            ->expectsOutputToContain('1.0.0')
            ->expectsOutputToContain('2.0.0');
    });

    it('skips class existence check with --skip-class-check', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('test-step')
            ->displayName('Test Step')
            ->job('NonExistent\\JobClass')
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('missing-class'),
            DefinitionVersion::fromString('1.0.0'),
            'Missing Class Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $this->registry->register($workflowDefinition);

        $this->artisan('maestro:validate', ['--skip-class-check' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('OK');
    });

    it('shows summary with mixed valid and invalid definitions', function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('valid-step')
            ->displayName('Valid Step')
            ->job(TestJob::class)
            ->build();

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('valid-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Valid Workflow',
            StepCollection::fromArray([$singleJobStepDefinition]),
        );

        $invalidDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('invalid-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Invalid Workflow',
            StepCollection::empty(),
        );

        $this->registry->register($workflowDefinition);
        $this->registry->register($invalidDefinition);

        $this->artisan('maestro:validate', ['--skip-class-check' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('1 valid, 1 invalid');
    });
});
