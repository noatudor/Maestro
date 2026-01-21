<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\Job;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Application\Job\DefaultIdempotencyKeyGenerator;
use Maestro\Workflow\Application\Job\JobDispatchService;
use Maestro\Workflow\Application\Orchestration\FailurePolicyHandler;
use Maestro\Workflow\Application\Orchestration\Listeners\JobFailedListener;
use Maestro\Workflow\Application\Orchestration\StepDispatcher;
use Maestro\Workflow\Application\Orchestration\StepFinalizer;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('JobFailedListener', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();
        $this->workflowDefinitionRegistry = new WorkflowDefinitionRegistry();

        $mock = Mockery::mock(Container::class);
        $mock->shouldReceive('make')->andReturnUsing(static fn (string $class): object => new $class());

        $stepDependencyChecker = new StepDependencyChecker($this->stepOutputRepository);
        $stepOutputStoreFactory = new StepOutputStoreFactory($this->stepOutputRepository);
        $workflowContextProviderFactory = new WorkflowContextProviderFactory($mock);
        $jobDispatchService = new JobDispatchService(
            $this->jobLedgerRepository,
            new DefaultIdempotencyKeyGenerator(),
        );

        $stepDispatcher = new StepDispatcher(
            $this->stepRunRepository,
            $jobDispatchService,
            $stepDependencyChecker,
            $stepOutputStoreFactory,
            $workflowContextProviderFactory,
            $this->workflowDefinitionRegistry,
        );

        $stepFinalizer = new StepFinalizer(
            $this->stepRunRepository,
            $this->jobLedgerRepository,
        );

        $failurePolicyHandler = new FailurePolicyHandler(
            $this->workflowRepository,
            $stepDispatcher,
        );

        $workflowAdvancer = new WorkflowAdvancer(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->workflowDefinitionRegistry,
            $stepFinalizer,
            $stepDispatcher,
            $failurePolicyHandler,
        );

        $this->listener = new JobFailedListener($workflowAdvancer);

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

    it('evaluates workflow when orchestrated job fails', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $jobUuid = 'test-job-uuid';

        $workflowInstance = WorkflowInstance::create(
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->key(),
            $this->workflowDefinitionRegistry->getLatest(
                DefinitionKey::fromString('test-workflow'),
            )->version(),
            $workflowId,
        );
        $workflowInstance->start(StepKey::fromString('step-1'));
        $workflowInstance->pause('Paused for test');
        $this->workflowRepository->save($workflowInstance);

        $orchestratedJob = new TestOrchestratedJob($workflowId, $stepRunId, $jobUuid);
        $jobFailed = createJobFailedEvent($orchestratedJob);

        $this->listener->handle($jobFailed);

        $updatedWorkflow = $this->workflowRepository->find($workflowId);
        expect($updatedWorkflow->state())->toBe(WorkflowState::Paused);
    });

    it('ignores non-orchestrated jobs', function (): void {
        $mockJob = Mockery::mock(Job::class);
        $mockJob->shouldReceive('payload')->andReturn([
            'data' => [
                'command' => serialize(new stdClass()),
            ],
        ]);

        $exception = new Exception('Job failed');
        $jobEvent = new JobFailed('default', $mockJob, $exception);

        $this->listener->handle($jobEvent);

        expect($this->workflowRepository->count())->toBe(0);
    });

    it('ignores jobs without command in payload', function (): void {
        $mockJob = Mockery::mock(Job::class);
        $mockJob->shouldReceive('payload')->andReturn([
            'data' => [],
        ]);

        $exception = new Exception('Job failed');
        $jobEvent = new JobFailed('default', $mockJob, $exception);

        $this->listener->handle($jobEvent);

        expect($this->workflowRepository->count())->toBe(0);
    });
});

function createJobFailedEvent(TestOrchestratedJob $testOrchestratedJob): JobFailed
{
    $mockJob = Mockery::mock(Job::class);
    $mockJob->shouldReceive('payload')->andReturn([
        'data' => [
            'command' => serialize($testOrchestratedJob),
        ],
    ]);

    return new JobFailed('default', $mockJob, new Exception('Job failed'));
}
