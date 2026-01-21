<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Dependency\StepDependencyChecker;
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('StepDependencyChecker', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryStepOutputRepository();
        $this->checker = new StepDependencyChecker($this->repository);
        $this->workflowId = WorkflowId::generate();
    });

    describe('areDependenciesMet', function (): void {
        it('returns true when step has no dependencies', function (): void {
            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->build();

            expect($this->checker->areDependenciesMet($this->workflowId, $step))->toBeTrue();
        });

        it('returns true when all dependencies exist', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));
            $this->repository->save($this->workflowId, new AnotherOutput(42));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            expect($this->checker->areDependenciesMet($this->workflowId, $step))->toBeTrue();
        });

        it('returns false when any dependency is missing', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            expect($this->checker->areDependenciesMet($this->workflowId, $step))->toBeFalse();
        });

        it('returns false when no dependencies exist', function (): void {
            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class)
                ->build();

            expect($this->checker->areDependenciesMet($this->workflowId, $step))->toBeFalse();
        });
    });

    describe('getMissingDependencies', function (): void {
        it('returns empty array when step has no dependencies', function (): void {
            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->build();

            expect($this->checker->getMissingDependencies($this->workflowId, $step))->toBe([]);
        });

        it('returns empty array when all dependencies exist', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class)
                ->build();

            expect($this->checker->getMissingDependencies($this->workflowId, $step))->toBe([]);
        });

        it('returns missing dependencies', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            $missing = $this->checker->getMissingDependencies($this->workflowId, $step);

            expect($missing)->toBe([AnotherOutput::class]);
        });

        it('returns all dependencies when none exist', function (): void {
            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            $missing = $this->checker->getMissingDependencies($this->workflowId, $step);

            expect($missing)->toBe([TestOutput::class, AnotherOutput::class]);
        });
    });

    describe('getSatisfiedDependencies', function (): void {
        it('returns empty array when step has no dependencies', function (): void {
            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->build();

            expect($this->checker->getSatisfiedDependencies($this->workflowId, $step))->toBe([]);
        });

        it('returns all dependencies when all exist', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));
            $this->repository->save($this->workflowId, new AnotherOutput(42));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            $satisfied = $this->checker->getSatisfiedDependencies($this->workflowId, $step);

            expect($satisfied)->toBe([TestOutput::class, AnotherOutput::class]);
        });

        it('returns only satisfied dependencies', function (): void {
            $this->repository->save($this->workflowId, new TestOutput('value'));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            $satisfied = $this->checker->getSatisfiedDependencies($this->workflowId, $step);

            expect($satisfied)->toBe([TestOutput::class]);
        });

        it('returns empty array when no dependencies are satisfied', function (): void {
            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class, AnotherOutput::class)
                ->build();

            expect($this->checker->getSatisfiedDependencies($this->workflowId, $step))->toBe([]);
        });
    });

    describe('isolation', function (): void {
        it('checks dependencies independently per workflow', function (): void {
            $workflowId2 = WorkflowId::generate();
            $this->repository->save($this->workflowId, new TestOutput('value'));

            $step = SingleJobStepBuilder::create('step-1')
                ->job(TestJob::class)
                ->displayName('Step 1')
                ->requires(TestOutput::class)
                ->build();

            expect($this->checker->areDependenciesMet($this->workflowId, $step))->toBeTrue();
            expect($this->checker->areDependenciesMet($workflowId2, $step))->toBeFalse();
        });
    });
});
