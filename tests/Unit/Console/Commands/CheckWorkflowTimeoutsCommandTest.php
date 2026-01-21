<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Console\Commands\CheckWorkflowTimeoutsCommand;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\StepKey;

describe('CheckWorkflowTimeoutsCommand', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $this->command = new CheckWorkflowTimeoutsCommand(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->workflowDefinitionRegistry,
        );
        $this->command->setLaravel(app());
    });

    it('marks timed out steps as failed', function (): void {
        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->timeout(stepTimeoutSeconds: 60)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);

        $workflowInstance = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $stepRun = StepRun::create(
            $workflowInstance->id,
            StepKey::fromString('step-1'),
        );
        $stepRun->start();

        $reflection = new ReflectionClass($stepRun);
        $reflectionProperty = $reflection->getProperty('startedAt');
        $reflectionProperty->setValue($stepRun, CarbonImmutable::now()->subSeconds(120));

        $this->stepRunRepository->save($stepRun);

        $exitCode = $this->command->handle();

        expect($exitCode)->toBe(0);

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        expect($updatedStepRun->status())->toBe(StepState::Failed);
        expect($updatedStepRun->failureCode())->toBe('STEP_TIMEOUT');
    });

    it('does not mark non-timed-out steps', function (): void {
        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->timeout(stepTimeoutSeconds: 3600)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);

        $workflowInstance = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $stepRun = StepRun::create(
            $workflowInstance->id,
            StepKey::fromString('step-1'),
        );
        $stepRun->start();
        $this->stepRunRepository->save($stepRun);

        $exitCode = $this->command->handle();

        expect($exitCode)->toBe(0);

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        expect($updatedStepRun->status())->toBe(StepState::Running);
    });

    it('skips workflows without current step', function (): void {
        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);

        $workflowInstance = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $workflowInstance->succeed();
        $this->workflowRepository->save($workflowInstance);

        $workflowInstance2 = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance2->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance2);

        $exitCode = $this->command->handle();

        expect($exitCode)->toBe(0);
    });

    it('skips steps that are not running', function (): void {
        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->timeout(stepTimeoutSeconds: 60)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);

        $workflowInstance = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $stepRun = StepRun::create(
            $workflowInstance->id,
            StepKey::fromString('step-1'),
        );
        $stepRun->start();
        $stepRun->succeed();
        $this->stepRunRepository->save($stepRun);

        $exitCode = $this->command->handle();

        expect($exitCode)->toBe(0);

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        expect($updatedStepRun->status())->toBe(StepState::Succeeded);
    });

    it('uses default timeout when step has no timeout configured', function (): void {
        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);

        $workflowInstance = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $stepRun = StepRun::create(
            $workflowInstance->id,
            StepKey::fromString('step-1'),
        );
        $stepRun->start();

        $reflection = new ReflectionClass($stepRun);
        $reflectionProperty = $reflection->getProperty('startedAt');
        $reflectionProperty->setValue($stepRun, CarbonImmutable::now()->subSeconds(7200));

        $this->stepRunRepository->save($stepRun);

        $exitCode = $this->command->handle();

        expect($exitCode)->toBe(0);

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        expect($updatedStepRun->status())->toBe(StepState::Failed);
    });

    it('skips steps with zero timeout', function (): void {
        $workflowDefinition = WorkflowDefinitionBuilder::create('test-workflow')
            ->displayName('Test Workflow')
            ->addStep(
                SingleJobStepBuilder::create('step-1')
                    ->displayName('Step 1')
                    ->job(TestJob::class)
                    ->timeout(stepTimeoutSeconds: 0)
                    ->build(),
            )
            ->build();
        $this->workflowDefinitionRegistry->register($workflowDefinition);

        $workflowInstance = WorkflowInstance::create(
            $workflowDefinition->key(),
            $workflowDefinition->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $stepRun = StepRun::create(
            $workflowInstance->id,
            StepKey::fromString('step-1'),
        );
        $stepRun->start();

        $reflection = new ReflectionClass($stepRun);
        $reflectionProperty = $reflection->getProperty('startedAt');
        $reflectionProperty->setValue($stepRun, CarbonImmutable::now()->subSeconds(7200));

        $this->stepRunRepository->save($stepRun);

        $exitCode = $this->command->handle();

        expect($exitCode)->toBe(0);

        $updatedStepRun = $this->stepRunRepository->find($stepRun->id);
        expect($updatedStepRun->status())->toBe(StepState::Running);
    });
});
