<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProvider;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestOrchestratedJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('OrchestratedJob', function (): void {
    beforeEach(function (): void {
        $this->workflowId = WorkflowId::generate();
        $this->stepRunId = StepRunId::generate();
        $this->jobUuid = 'test-job-uuid-123';
    });

    it('stores correlation metadata', function (): void {
        $job = new TestOrchestratedJob(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
        );

        expect($job->workflowId)->toBe($this->workflowId);
        expect($job->stepRunId)->toBe($this->stepRunId);
        expect($job->jobUuid)->toBe($this->jobUuid);
    });

    it('returns correlation metadata array', function (): void {
        $job = new TestOrchestratedJob(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
        );

        $metadata = $job->correlationMetadata();

        expect($metadata)->toBe([
            'workflow_id' => $this->workflowId->value,
            'step_run_id' => $this->stepRunId->value,
            'job_uuid' => $this->jobUuid,
        ]);
    });

    it('executes via handle method', function (): void {
        $job = new TestOrchestratedJob(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
        );

        $outputRepository = new InMemoryStepOutputRepository();
        $outputStore = new StepOutputStore($this->workflowId, $outputRepository);

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Test Workflow',
            StepCollection::empty(),
        );

        $mock = Mockery::mock(Container::class);
        $contextProvider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $mock);

        $job->handle($contextProvider, $outputStore);

        expect($job->executed)->toBeTrue();
    });

    it('can write outputs during execution', function (): void {
        $job = new TestOrchestratedJob(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
        );

        $output = new TestOutput('test-value');
        $job->outputToWrite = $output;

        $outputRepository = new InMemoryStepOutputRepository();
        $outputStore = new StepOutputStore($this->workflowId, $outputRepository);

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Test Workflow',
            StepCollection::empty(),
        );

        $mock = Mockery::mock(Container::class);
        $contextProvider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $mock);

        $job->handle($contextProvider, $outputStore);

        expect($outputRepository->has($this->workflowId, TestOutput::class))->toBeTrue();
    });

    it('allows setting context provider manually', function (): void {
        $job = new TestOrchestratedJob(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
        );

        $workflowDefinition = WorkflowDefinition::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
            'Test Workflow',
            StepCollection::empty(),
        );

        $mock = Mockery::mock(Container::class);
        $contextProvider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $mock);

        $job->setContextProvider($contextProvider);

        expect($job->getContext())->toBeNull();
    });

    it('allows setting output store manually', function (): void {
        $job = new TestOrchestratedJob(
            $this->workflowId,
            $this->stepRunId,
            $this->jobUuid,
        );

        $outputRepository = new InMemoryStepOutputRepository();
        $outputStore = new StepOutputStore($this->workflowId, $outputRepository);

        $job->setOutputStore($outputStore);

        expect($job->getOutputStore())->toBe($outputStore);
    });
});
