<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\StepRunHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\WorkflowHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepRunRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('Atomic step finalization with real database', function (): void {
    beforeEach(function (): void {
        $this->hydrator = new StepRunHydrator();
        $this->repository = new EloquentStepRunRepository($this->hydrator);

        $workflowHydrator = new WorkflowHydrator();
        $workflowRepository = new EloquentWorkflowRepository(
            $workflowHydrator,
            DB::connection(),
        );

        $workflowInstance = WorkflowInstance::create(
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
        );
        $workflowRepository->save($workflowInstance);

        $this->workflowId = $workflowInstance->id;
    });

    describe('finalizeAsSucceeded', function (): void {
        it('atomically finalizes a running step as succeeded', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $stepRun->start();
            $this->repository->save($stepRun);

            $finishedAt = CarbonImmutable::now();
            $result = $this->repository->finalizeAsSucceeded($stepRun->id, $finishedAt);

            expect($result)->toBeTrue();

            $reloaded = $this->repository->findOrFail($stepRun->id);
            expect($reloaded->status())->toBe(StepState::Succeeded);
            expect($reloaded->finishedAt())->not->toBeNull();
        });

        it('returns false when step is not in running state', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->repository->save($stepRun);

            $finishedAt = CarbonImmutable::now();
            $result = $this->repository->finalizeAsSucceeded($stepRun->id, $finishedAt);

            expect($result)->toBeFalse();

            $reloaded = $this->repository->findOrFail($stepRun->id);
            expect($reloaded->status())->toBe(StepState::Pending);
        });

        it('returns false when step was already finalized by another process', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $stepRun->start();
            $this->repository->save($stepRun);

            $finishedAt = CarbonImmutable::now();
            $firstResult = $this->repository->finalizeAsSucceeded($stepRun->id, $finishedAt);
            $secondResult = $this->repository->finalizeAsSucceeded($stepRun->id, $finishedAt);

            expect($firstResult)->toBeTrue();
            expect($secondResult)->toBeFalse();
        });
    });

    describe('finalizeAsFailed', function (): void {
        it('atomically finalizes a running step as failed', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $stepRun->start();
            $this->repository->save($stepRun);

            $finishedAt = CarbonImmutable::now();
            $result = $this->repository->finalizeAsFailed(
                $stepRun->id,
                'JOB_FAILED',
                'Job execution failed',
                1,
                $finishedAt,
            );

            expect($result)->toBeTrue();

            $reloaded = $this->repository->findOrFail($stepRun->id);
            expect($reloaded->status())->toBe(StepState::Failed);
            expect($reloaded->failureCode())->toBe('JOB_FAILED');
            expect($reloaded->failureMessage())->toBe('Job execution failed');
            expect($reloaded->failedJobCount())->toBe(1);
        });

        it('returns false when step is not in running state', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->repository->save($stepRun);

            $finishedAt = CarbonImmutable::now();
            $result = $this->repository->finalizeAsFailed(
                $stepRun->id,
                'JOB_FAILED',
                'Job execution failed',
                1,
                $finishedAt,
            );

            expect($result)->toBeFalse();
        });

        it('prevents race condition when two processes try to finalize', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $stepRun->start();
            $this->repository->save($stepRun);

            $finishedAt = CarbonImmutable::now();
            $successResult = $this->repository->finalizeAsSucceeded($stepRun->id, $finishedAt);
            $failResult = $this->repository->finalizeAsFailed(
                $stepRun->id,
                'JOB_FAILED',
                'Job execution failed',
                1,
                $finishedAt,
            );

            expect($successResult)->toBeTrue();
            expect($failResult)->toBeFalse();

            $reloaded = $this->repository->findOrFail($stepRun->id);
            expect($reloaded->status())->toBe(StepState::Succeeded);
        });
    });

    describe('updateStatusAtomically', function (): void {
        it('atomically updates status when from state matches', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->repository->save($stepRun);

            $result = $this->repository->updateStatusAtomically(
                $stepRun->id,
                StepState::Pending,
                StepState::Running,
            );

            expect($result)->toBeTrue();

            $reloaded = $this->repository->findOrFail($stepRun->id);
            expect($reloaded->status())->toBe(StepState::Running);
        });

        it('returns false when from state does not match', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $stepRun->start();
            $this->repository->save($stepRun);

            $result = $this->repository->updateStatusAtomically(
                $stepRun->id,
                StepState::Pending,
                StepState::Running,
            );

            expect($result)->toBeFalse();

            $reloaded = $this->repository->findOrFail($stepRun->id);
            expect($reloaded->status())->toBe(StepState::Running);
        });

        it('only one update succeeds in concurrent scenario', function (): void {
            $stepRun = StepRun::create(
                workflowId: $this->workflowId,
                stepKey: StepKey::fromString('test-step'),
                attempt: 1,
            );
            $this->repository->save($stepRun);

            $firstResult = $this->repository->updateStatusAtomically(
                $stepRun->id,
                StepState::Pending,
                StepState::Running,
            );

            $secondResult = $this->repository->updateStatusAtomically(
                $stepRun->id,
                StepState::Pending,
                StepState::Running,
            );

            expect($firstResult)->toBeTrue();
            expect($secondResult)->toBeFalse();
        });
    });
});
