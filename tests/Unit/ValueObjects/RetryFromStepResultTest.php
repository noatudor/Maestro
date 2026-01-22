<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\RetryFromStepResult;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;

describe('RetryFromStepResult', function (): void {
    beforeEach(function (): void {
        $this->workflowInstance = WorkflowInstance::create(
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
        );
        $this->retryFromStepKey = StepKey::fromString('step-2');
        $this->newStepRunId = StepRunId::generate();
    });

    describe('create', function (): void {
        it('creates result with no superseded steps', function (): void {
            $retryFromStepResult = RetryFromStepResult::create(
                workflowInstance: $this->workflowInstance,
                retryFromStepKey: $this->retryFromStepKey,
                newStepRunId: $this->newStepRunId,
                supersededStepRunIds: [],
                clearedOutputStepKeys: [],
            );

            expect($retryFromStepResult->workflowInstance)->toBe($this->workflowInstance)
                ->and($retryFromStepResult->retryFromStepKey)->toBe($this->retryFromStepKey)
                ->and($retryFromStepResult->newStepRunId)->toBe($this->newStepRunId)
                ->and($retryFromStepResult->supersededStepRunIds)->toBe([])
                ->and($retryFromStepResult->clearedOutputStepKeys)->toBe([])
                ->and($retryFromStepResult->compensationExecuted)->toBeFalse();
        });

        it('creates result with superseded steps', function (): void {
            $supersededIds = [StepRunId::generate(), StepRunId::generate()];
            $clearedKeys = [StepKey::fromString('step-2'), StepKey::fromString('step-3')];

            $retryFromStepResult = RetryFromStepResult::create(
                workflowInstance: $this->workflowInstance,
                retryFromStepKey: $this->retryFromStepKey,
                newStepRunId: $this->newStepRunId,
                supersededStepRunIds: $supersededIds,
                clearedOutputStepKeys: $clearedKeys,
                compensationExecuted: true,
            );

            expect($retryFromStepResult->supersededStepRunIds)->toBe($supersededIds)
                ->and($retryFromStepResult->clearedOutputStepKeys)->toBe($clearedKeys)
                ->and($retryFromStepResult->compensationExecuted)->toBeTrue();
        });
    });

    describe('supersededCount', function (): void {
        it('returns 0 when no step runs were superseded', function (): void {
            $retryFromStepResult = RetryFromStepResult::create(
                workflowInstance: $this->workflowInstance,
                retryFromStepKey: $this->retryFromStepKey,
                newStepRunId: $this->newStepRunId,
                supersededStepRunIds: [],
                clearedOutputStepKeys: [],
            );

            expect($retryFromStepResult->supersededCount())->toBe(0);
        });

        it('returns count of superseded step runs', function (): void {
            $retryFromStepResult = RetryFromStepResult::create(
                workflowInstance: $this->workflowInstance,
                retryFromStepKey: $this->retryFromStepKey,
                newStepRunId: $this->newStepRunId,
                supersededStepRunIds: [StepRunId::generate(), StepRunId::generate(), StepRunId::generate()],
                clearedOutputStepKeys: [],
            );

            expect($retryFromStepResult->supersededCount())->toBe(3);
        });
    });

    describe('clearedOutputCount', function (): void {
        it('returns 0 when no outputs were cleared', function (): void {
            $retryFromStepResult = RetryFromStepResult::create(
                workflowInstance: $this->workflowInstance,
                retryFromStepKey: $this->retryFromStepKey,
                newStepRunId: $this->newStepRunId,
                supersededStepRunIds: [],
                clearedOutputStepKeys: [],
            );

            expect($retryFromStepResult->clearedOutputCount())->toBe(0);
        });

        it('returns count of cleared output step keys', function (): void {
            $retryFromStepResult = RetryFromStepResult::create(
                workflowInstance: $this->workflowInstance,
                retryFromStepKey: $this->retryFromStepKey,
                newStepRunId: $this->newStepRunId,
                supersededStepRunIds: [],
                clearedOutputStepKeys: [StepKey::fromString('step-2'), StepKey::fromString('step-3')],
            );

            expect($retryFromStepResult->clearedOutputCount())->toBe(2);
        });
    });
});
