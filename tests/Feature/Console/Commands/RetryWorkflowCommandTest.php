<?php

declare(strict_types=1);

use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('RetryWorkflowCommand', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryWorkflowRepository();
        $this->app->instance(WorkflowRepository::class, $this->repository);
    });

    it('fails when workflow is not found', function (): void {
        $this->artisan('maestro:retry', [
            'workflow' => 'non-existent-id',
            '--force' => true,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Workflow not found');
    });

    it('fails when workflow is not in failed state', function (): void {
        $workflowId = WorkflowId::generate();
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));

        $this->repository->save($workflowInstance);

        $mock = Mockery::mock(WorkflowManager::class);
        $this->app->instance(WorkflowManager::class, $mock);

        $this->artisan('maestro:retry', [
            'workflow' => $workflowId->value,
            '--force' => true,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Workflow is not in failed state');
    });

    it('retries a failed workflow with force option', function (): void {
        $workflowId = WorkflowId::generate();
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $workflowInstance->fail('TEST_ERROR', 'Test failure');

        $this->repository->save($workflowInstance);

        $mock = Mockery::mock(WorkflowManager::class);
        $mock->shouldReceive('retryWorkflow')
            ->once()
            ->with(Mockery::on(static fn ($id): bool => $id->value === $workflowId->value))
            ->andReturn($workflowInstance);

        $this->app->instance(WorkflowManager::class, $mock);

        $this->artisan('maestro:retry', [
            'workflow' => $workflowId->value,
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('has been retried successfully');
    });

    it('handles invalid state transition exception', function (): void {
        $workflowId = WorkflowId::generate();
        $workflowInstance = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $workflowInstance->fail('TEST_ERROR', 'Test failure');

        $this->repository->save($workflowInstance);

        $mock = Mockery::mock(WorkflowManager::class);
        $mock->shouldReceive('retryWorkflow')
            ->once()
            ->andThrow(InvalidStateTransitionException::forWorkflow(WorkflowState::Cancelled, WorkflowState::Running));

        $this->app->instance(WorkflowManager::class, $mock);

        $this->artisan('maestro:retry', [
            'workflow' => $workflowId->value,
            '--force' => true,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Cannot retry workflow');
    });
});
