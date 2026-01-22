<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Query\WorkflowQueryService;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Http\Responses\WorkflowDetailDTO;
use Maestro\Workflow\Http\Responses\WorkflowListDTO;
use Maestro\Workflow\Http\Responses\WorkflowStatusDTO;
use Maestro\Workflow\Tests\Fakes\InMemoryJobLedgerRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryStepRunRepository;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowQueryService', function (): void {
    beforeEach(function (): void {
        $this->workflowRepository = new InMemoryWorkflowRepository();
        $this->stepRunRepository = new InMemoryStepRunRepository();
        $this->jobLedgerRepository = new InMemoryJobLedgerRepository();
        $this->stepOutputRepository = new InMemoryStepOutputRepository();

        $this->service = new WorkflowQueryService(
            $this->workflowRepository,
            $this->stepRunRepository,
            $this->jobLedgerRepository,
            $this->stepOutputRepository,
        );
    });

    describe('getWorkflowStatus', function (): void {
        it('returns status dto for existing workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflowInstance);

            $result = $this->service->getWorkflowStatus($workflowInstance->id);

            expect($result)->toBeInstanceOf(WorkflowStatusDTO::class);
            expect($result->id)->toBe($workflowInstance->id->value);
            expect($result->state)->toBe(WorkflowState::Pending);
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->service->getWorkflowStatus(WorkflowId::generate()))
                ->toThrow(WorkflowNotFoundException::class);
        });
    });

    describe('getWorkflowDetail', function (): void {
        it('returns detail dto with steps and jobs', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'), totalJobCount: 2);
            $stepRun->start();
            $this->stepRunRepository->save($stepRun);

            $jobRecord = JobRecord::create($workflowInstance->id, $stepRun->id, 'job-1', 'TestJob', 'default');
            $job2 = JobRecord::create($workflowInstance->id, $stepRun->id, 'job-2', 'TestJob', 'default');
            $this->jobLedgerRepository->save($jobRecord);
            $this->jobLedgerRepository->save($job2);

            $result = $this->service->getWorkflowDetail($workflowInstance->id);

            expect($result)->toBeInstanceOf(WorkflowDetailDTO::class);
            expect($result->workflow->id)->toBe($workflowInstance->id->value);
            expect($result->steps)->toHaveCount(1);
            expect($result->jobs)->toHaveCount(2);
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->service->getWorkflowDetail(WorkflowId::generate()))
                ->toThrow(WorkflowNotFoundException::class);
        });
    });

    describe('getWorkflowsByState', function (): void {
        it('returns workflows in specified state', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $running2 = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $running2->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($running2);

            $pending = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($pending);

            $result = $this->service->getWorkflowsByState(WorkflowState::Running);

            expect($result)->toBeInstanceOf(WorkflowListDTO::class);
            expect($result->total)->toBe(2);
        });
    });

    describe('getRunningWorkflows', function (): void {
        it('returns only running workflows', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflowInstance);

            $paused = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $paused->start(StepKey::fromString('step-1'));
            $paused->pause('paused');
            $this->workflowRepository->save($paused);

            $result = $this->service->getRunningWorkflows();

            expect($result->total)->toBe(1);
        });
    });

    describe('getPausedWorkflows', function (): void {
        it('returns only paused workflows', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->pause('waiting');
            $this->workflowRepository->save($workflowInstance);

            $result = $this->service->getPausedWorkflows();

            expect($result->total)->toBe(1);
        });
    });

    describe('getFailedWorkflows', function (): void {
        it('returns only failed workflows', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->fail('ERROR', 'failed');
            $this->workflowRepository->save($workflowInstance);

            $result = $this->service->getFailedWorkflows();

            expect($result->total)->toBe(1);
        });
    });

    describe('getWorkflowsByDefinition', function (): void {
        it('returns workflows for definition key', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('order-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflowInstance);

            $workflow2 = WorkflowInstance::create(
                DefinitionKey::fromString('order-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflow2);

            $other = WorkflowInstance::create(
                DefinitionKey::fromString('other-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($other);

            $result = $this->service->getWorkflowsByDefinition(
                DefinitionKey::fromString('order-workflow'),
            );

            expect($result->total)->toBe(2);
        });
    });

    describe('getWorkflowSteps', function (): void {
        it('returns step runs for workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $step2 = StepRun::create($workflowInstance->id, StepKey::fromString('step-2'));
            $this->stepRunRepository->save($stepRun);
            $this->stepRunRepository->save($step2);

            $result = $this->service->getWorkflowSteps($workflowInstance->id);

            expect($result)->toHaveCount(2);
        });

        it('throws for non-existent workflow', function (): void {
            expect(fn () => $this->service->getWorkflowSteps(WorkflowId::generate()))
                ->toThrow(WorkflowNotFoundException::class);
        });
    });

    describe('getWorkflowJobs', function (): void {
        it('returns job records for workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflowInstance);

            $stepRun = StepRun::create($workflowInstance->id, StepKey::fromString('step-1'));
            $this->stepRunRepository->save($stepRun);

            $jobRecord = JobRecord::create($workflowInstance->id, $stepRun->id, 'job-uuid', 'TestJob', 'default');
            $this->jobLedgerRepository->save($jobRecord);

            $result = $this->service->getWorkflowJobs($workflowInstance->id);

            expect($result)->toHaveCount(1);
        });
    });

    describe('workflowExists', function (): void {
        it('returns true for existing workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflowInstance);

            expect($this->service->workflowExists($workflowInstance->id))->toBeTrue();
        });

        it('returns false for non-existent workflow', function (): void {
            expect($this->service->workflowExists(WorkflowId::generate()))->toBeFalse();
        });
    });

    describe('findWorkflow', function (): void {
        it('returns workflow instance', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $this->workflowRepository->save($workflowInstance);

            $result = $this->service->findWorkflow($workflowInstance->id);

            expect($result)->toBeInstanceOf(WorkflowInstance::class);
            expect($result->id->equals($workflowInstance->id))->toBeTrue();
        });
    });
});
