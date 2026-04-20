<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('Concurrent Workflow Stress Tests', function () {
    beforeEach(function () {
        Event::fake();
        Queue::fake();

        $this->workflowRepository = app(WorkflowRepository::class);
        $this->registry = app(WorkflowDefinitionRegistry::class);
        $this->advancer = app(WorkflowAdvancer::class);

        $definition = WorkflowDefinitionBuilder::create('stress-test-workflow')
            ->version('1.0.0')
            ->displayName('Stress Test Workflow')
            ->singleJob('step-1', fn ($b) => $b->job(TestJob::class))
            ->singleJob('step-2', fn ($b) => $b->job(TestJob::class))
            ->singleJob('step-3', fn ($b) => $b->job(TestJob::class))
            ->build();

        $this->registry->register($definition);
    });

    it('handles 100 concurrent workflows', function () {
        $workflowIds = [];

        for ($i = 0; $i < 100; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('stress-test-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $this->workflowRepository->save($workflow);
            $workflowIds[] = $workflow->id;
        }

        expect(count($workflowIds))->toBe(100);

        foreach ($workflowIds as $workflowId) {
            $this->advancer->evaluate($workflowId);
        }

        $runningCount = 0;
        foreach ($workflowIds as $workflowId) {
            $workflow = $this->workflowRepository->find($workflowId);
            if ($workflow->state() === WorkflowState::Running) {
                $runningCount++;
            }
        }

        expect($runningCount)->toBe(100);
    });

    it('handles 500 concurrent workflows in batches', function () {
        $totalWorkflows = 500;
        $batchSize = 50;
        $workflowIds = [];

        for ($batch = 0; $batch < $totalWorkflows / $batchSize; $batch++) {
            DB::beginTransaction();

            for ($i = 0; $i < $batchSize; $i++) {
                $workflow = WorkflowInstance::create(
                    DefinitionKey::fromString('stress-test-workflow'),
                    DefinitionVersion::fromString('1.0.0'),
                );
                $this->workflowRepository->save($workflow);
                $workflowIds[] = $workflow->id;
            }

            DB::commit();
        }

        expect(count($workflowIds))->toBe($totalWorkflows);

        foreach (array_chunk($workflowIds, $batchSize) as $batch) {
            foreach ($batch as $workflowId) {
                $this->advancer->evaluate($workflowId);
            }
        }

        $runningWorkflows = $this->workflowRepository->findRunning();
        expect(count($runningWorkflows))->toBe($totalWorkflows);
    });

    it('handles mixed workflow states', function () {
        $workflows = [];

        for ($i = 0; $i < 30; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('stress-test-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );

            if ($i % 3 === 0) {
                $workflow->start(StepKey::fromString('step-1'));
            } elseif ($i % 3 === 1) {
                $workflow->start(StepKey::fromString('step-1'));
                $workflow->pause('Test pause');
            }

            $this->workflowRepository->save($workflow);
            $workflows[] = $workflow;
        }

        $pending = $this->workflowRepository->findByState(WorkflowState::Pending);
        $running = $this->workflowRepository->findByState(WorkflowState::Running);
        $paused = $this->workflowRepository->findByState(WorkflowState::Paused);

        expect(count($pending))->toBe(10)
            ->and(count($running))->toBe(10)
            ->and(count($paused))->toBe(10);
    });

    it('handles 1000 workflow creations and queries', function () {
        $workflowIds = [];

        for ($i = 0; $i < 1000; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('stress-test-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $this->workflowRepository->save($workflow);
            $workflowIds[] = $workflow->id;
        }

        expect(count($workflowIds))->toBe(1000);

        foreach ($workflowIds as $workflowId) {
            expect($this->workflowRepository->exists($workflowId))->toBeTrue();
        }

        $found = $this->workflowRepository->findByDefinitionKey('stress-test-workflow');
        expect(count($found))->toBe(1000);
    });

    it('handles concurrent state transitions', function () {
        $workflows = [];

        for ($i = 0; $i < 100; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('stress-test-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $workflow->start(StepKey::fromString('step-1'));
            $this->workflowRepository->save($workflow);
            $workflows[] = $workflow;
        }

        foreach ($workflows as $index => $workflow) {
            $found = $this->workflowRepository->find($workflow->id);

            if ($index % 4 === 0) {
                $found->pause('Batch pause');
            } elseif ($index % 4 === 1) {
                $found->succeed();
            } elseif ($index % 4 === 2) {
                $found->fail('BATCH_ERROR', 'Batch failure');
            } elseif ($index % 4 === 3) {
                $found->cancel();
            }

            $this->workflowRepository->save($found);
        }

        $paused = $this->workflowRepository->findByState(WorkflowState::Paused);
        $succeeded = $this->workflowRepository->findByState(WorkflowState::Succeeded);
        $failed = $this->workflowRepository->findByState(WorkflowState::Failed);
        $cancelled = $this->workflowRepository->findByState(WorkflowState::Cancelled);

        expect(count($paused))->toBe(25)
            ->and(count($succeeded))->toBe(25)
            ->and(count($failed))->toBe(25)
            ->and(count($cancelled))->toBe(25);
    });

    it('handles rapid workflow creation and deletion', function () {
        $createdIds = [];

        for ($i = 0; $i < 200; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('stress-test-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $this->workflowRepository->save($workflow);
            $createdIds[] = $workflow->id;
        }

        foreach (array_slice($createdIds, 0, 100) as $workflowId) {
            $this->workflowRepository->delete($workflowId);
        }

        $remaining = $this->workflowRepository->findByDefinitionKey('stress-test-workflow');
        expect(count($remaining))->toBe(100);

        foreach (array_slice($createdIds, 0, 100) as $workflowId) {
            expect($this->workflowRepository->exists($workflowId))->toBeFalse();
        }

        foreach (array_slice($createdIds, 100) as $workflowId) {
            expect($this->workflowRepository->exists($workflowId))->toBeTrue();
        }
    });

    it('handles 100 workflows with locking', function () {
        $workflows = [];

        for ($i = 0; $i < 100; $i++) {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('stress-test-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $this->workflowRepository->save($workflow);
            $workflows[] = $workflow;
        }

        $locks = [];
        foreach ($workflows as $index => $workflow) {
            $lockId = "lock-{$index}";
            $acquired = $this->workflowRepository->acquireApplicationLock($workflow->id, $lockId);
            if ($acquired) {
                $locks[$workflow->id->value] = $lockId;
            }
        }

        expect(count($locks))->toBe(100);

        foreach ($locks as $workflowIdValue => $lockId) {
            $workflowId = WorkflowId::fromString($workflowIdValue);
            $released = $this->workflowRepository->releaseApplicationLock($workflowId, $lockId);
            expect($released)->toBeTrue();
        }
    });
});
