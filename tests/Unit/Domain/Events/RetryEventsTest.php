<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Events\RetryFromStepCompleted;
use Maestro\Workflow\Domain\Events\RetryFromStepInitiated;
use Maestro\Workflow\Domain\Events\StepRunSuperseded;
use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('RetryFromStepInitiated', static function (): void {
    it('creates event with all properties', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::fromString('1.0.0');
        $stepKey = StepKey::fromString('step-2');
        $now = CarbonImmutable::now();

        $event = new RetryFromStepInitiated(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            retryFromStepKey: $stepKey,
            retryMode: RetryMode::RetryOnly,
            affectedStepKeys: ['step-2', 'step-3'],
            initiatedBy: 'admin',
            reason: 'Test retry',
            occurredAt: $now,
        );

        expect($event->workflowId)->toBe($workflowId)
            ->and($event->definitionKey)->toBe($definitionKey)
            ->and($event->definitionVersion)->toBe($definitionVersion)
            ->and($event->retryFromStepKey)->toBe($stepKey)
            ->and($event->retryMode)->toBe(RetryMode::RetryOnly)
            ->and($event->affectedStepKeys)->toBe(['step-2', 'step-3'])
            ->and($event->initiatedBy)->toBe('admin')
            ->and($event->reason)->toBe('Test retry')
            ->and($event->occurredAt)->toBe($now);
    });
});

describe('StepRunSuperseded', static function (): void {
    it('creates event with all properties', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();
        $stepKey = StepKey::fromString('step-2');
        $supersededById = StepRunId::generate();
        $now = CarbonImmutable::now();

        $event = new StepRunSuperseded(
            workflowId: $workflowId,
            stepRunId: $stepRunId,
            stepKey: $stepKey,
            attempt: 1,
            supersededById: $supersededById,
            occurredAt: $now,
        );

        expect($event->workflowId)->toBe($workflowId)
            ->and($event->stepRunId)->toBe($stepRunId)
            ->and($event->stepKey)->toBe($stepKey)
            ->and($event->attempt)->toBe(1)
            ->and($event->supersededById)->toBe($supersededById)
            ->and($event->occurredAt)->toBe($now);
    });
});

describe('RetryFromStepCompleted', static function (): void {
    it('creates event with all properties', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::fromString('1.0.0');
        $stepKey = StepKey::fromString('step-2');
        $newStepRunId = StepRunId::generate();
        $now = CarbonImmutable::now();

        $event = new RetryFromStepCompleted(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            retryFromStepKey: $stepKey,
            newStepRunId: $newStepRunId,
            retryMode: RetryMode::CompensateThenRetry,
            supersededStepRunCount: 2,
            clearedOutputCount: 3,
            compensationExecuted: true,
            occurredAt: $now,
        );

        expect($event->workflowId)->toBe($workflowId)
            ->and($event->definitionKey)->toBe($definitionKey)
            ->and($event->definitionVersion)->toBe($definitionVersion)
            ->and($event->retryFromStepKey)->toBe($stepKey)
            ->and($event->newStepRunId)->toBe($newStepRunId)
            ->and($event->retryMode)->toBe(RetryMode::CompensateThenRetry)
            ->and($event->supersededStepRunCount)->toBe(2)
            ->and($event->clearedOutputCount)->toBe(3)
            ->and($event->compensationExecuted)->toBeTrue()
            ->and($event->occurredAt)->toBe($now);
    });
});
