<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Query\WorkflowQueryService;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('ListWorkflowsCommand', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryWorkflowRepository();

        $this->app->forgetInstance(WorkflowQueryService::class);

        $this->app->instance(WorkflowRepository::class, $this->repository);
        $this->app->instance(StepRunRepository::class, new InMemoryStepRunRepository());
        $this->app->instance(JobLedgerRepository::class, new InMemoryJobLedgerRepository());
        $this->app->instance(StepOutputRepository::class, new InMemoryStepOutputRepository());
    });

    it('shows empty message when no running workflows', function (): void {
        $this->artisan('maestro:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('No workflows found');
    });

    it('lists running workflows by default', function (): void {
        $workflowId = WorkflowId::generate();
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));

        $this->repository->save($workflowInstance);

        $this->artisan('maestro:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('test-workflow')
            ->expectsOutputToContain('Showing 1 of 1 workflows');
    });

    it('filters by state option', function (): void {
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('running-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            WorkflowId::generate(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->repository->save($workflowInstance);

        $failedWorkflow = WorkflowInstance::create(
            DefinitionKey::fromString('failed-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            WorkflowId::generate(),
        );
        $failedWorkflow->start(StepKey::fromString('step-1'));
        $failedWorkflow->fail('ERROR', 'Test failure');
        $this->repository->save($failedWorkflow);

        $this->artisan('maestro:list', ['--state' => 'failed'])
            ->assertExitCode(0)
            ->expectsOutputToContain('failed-workflow');
    });

    it('fails with invalid state', function (): void {
        $this->artisan('maestro:list', ['--state' => 'invalid-state'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid state');
    });

    it('filters by definition option', function (): void {
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('workflow-one'),
            DefinitionVersion::fromString('1.0.0'),
            WorkflowId::generate(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->repository->save($workflowInstance);

        $workflow2 = WorkflowInstance::create(
            DefinitionKey::fromString('workflow-two'),
            DefinitionVersion::fromString('1.0.0'),
            WorkflowId::generate(),
        );
        $workflow2->start(StepKey::fromString('step-1'));
        $this->repository->save($workflow2);

        $this->artisan('maestro:list', ['--definition' => 'workflow-one'])
            ->assertExitCode(0)
            ->expectsOutputToContain('workflow-one')
            ->expectsOutputToContain('Showing 1 of 1 workflows');
    });

    it('fails with invalid definition key', function (): void {
        $this->artisan('maestro:list', ['--definition' => 'invalid key with spaces'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid definition key');
    });

    it('respects limit option', function (): void {
        for ($i = 1; $i <= 5; $i++) {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('workflow-'.$i),
                DefinitionVersion::fromString('1.0.0'),
                WorkflowId::generate(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->repository->save($workflowInstance);
        }

        $this->artisan('maestro:list', [
            '--state' => 'running',
            '--limit' => '2',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Showing 2 of 5 workflows');
    });

    it('outputs JSON with --json option', function (): void {
        $workflowId = WorkflowId::generate();
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));

        $this->repository->save($workflowInstance);

        $this->artisan('maestro:list', [
            '--state' => 'running',
            '--json' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"workflows"');
    });
});
