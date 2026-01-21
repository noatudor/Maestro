<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Job\Middleware\JobContextMiddleware;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobContextMiddleware', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $mock = Mockery::mock(Container::class);
        $mock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $workflowContextProviderFactory = new WorkflowContextProviderFactory($mock);
        $stepOutputStoreFactory = new StepOutputStoreFactory($this->stepOutputRepository);

        $this->middleware = new JobContextMiddleware(
            $this->workflowRepository,
            $this->workflowDefinitionRegistry,
            $workflowContextProviderFactory,
            $stepOutputStoreFactory,
        );

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
    });

    it('injects context provider into job', function (): void {
        $workflowInstance = WorkflowInstance::create(
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->key(),
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $job = new TestOrchestratedJob(
            $workflowInstance->id,
            StepRunId::generate(),
            'test-job-uuid',
        );

        $executed = false;
        $this->middleware->handle($job, static function ($passedJob) use (&$executed): void {
            $executed = true;
            expect($passedJob->getContext())->toBeNull();
        });

        expect($executed)->toBeTrue();
    });

    it('injects output store into job', function (): void {
        $workflowInstance = WorkflowInstance::create(
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->key(),
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $job = new TestOrchestratedJob(
            $workflowInstance->id,
            StepRunId::generate(),
            'test-job-uuid',
        );

        $captured = null;
        $this->middleware->handle($job, static function ($passedJob) use (&$captured): void {
            $captured = $passedJob->getOutputStore();
        });

        expect($captured)->not->toBeNull();
    });

    it('throws when workflow not found', function (): void {
        $job = new TestOrchestratedJob(
            WorkflowId::generate(),
            StepRunId::generate(),
            'test-job-uuid',
        );

        expect(fn () => $this->middleware->handle($job, static fn (): null => null))
            ->toThrow(WorkflowNotFoundException::class);
    });

    it('calls next middleware', function (): void {
        $workflowInstance = WorkflowInstance::create(
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->key(),
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->version(),
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $this->workflowRepository->save($workflowInstance);

        $job = new TestOrchestratedJob(
            $workflowInstance->id,
            StepRunId::generate(),
            'test-job-uuid',
        );

        $nextCalled = false;
        $this->middleware->handle($job, static function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        expect($nextCalled)->toBeTrue();
    });
});
