<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Events\TriggerReceived;
use Maestro\Workflow\Domain\Events\TriggerTimedOut;
use Maestro\Workflow\Domain\Events\TriggerValidationFailed;
use Maestro\Workflow\Domain\Events\WorkflowAutoResumed;
use Maestro\Workflow\Domain\Events\WorkflowAwaitingTrigger;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\TriggerPayloadId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('Trigger Events', static function (): void {
    describe('WorkflowAwaitingTrigger', static function (): void {
        it('captures all required data', function (): void {
            $workflowId = WorkflowId::generate();
            $definitionKey = DefinitionKey::fromString('test-workflow');
            $definitionVersion = DefinitionVersion::fromString('1.0.0');
            $stepKey = StepKey::fromString('step-1');
            $timeoutAt = CarbonImmutable::now()->addHours(24);
            $scheduledResumeAt = CarbonImmutable::now()->addHours(2);
            $occurredAt = CarbonImmutable::now();

            $event = new WorkflowAwaitingTrigger(
                workflowId: $workflowId,
                definitionKey: $definitionKey,
                definitionVersion: $definitionVersion,
                afterStepKey: $stepKey,
                triggerKey: 'approval',
                timeoutAt: $timeoutAt,
                scheduledResumeAt: $scheduledResumeAt,
                occurredAt: $occurredAt,
            );

            expect($event->workflowId)->toBe($workflowId)
                ->and($event->definitionKey)->toBe($definitionKey)
                ->and($event->definitionVersion)->toBe($definitionVersion)
                ->and($event->afterStepKey)->toBe($stepKey)
                ->and($event->triggerKey)->toBe('approval')
                ->and($event->timeoutAt)->toBe($timeoutAt)
                ->and($event->scheduledResumeAt)->toBe($scheduledResumeAt)
                ->and($event->occurredAt)->toBe($occurredAt);
        });
    });

    describe('TriggerReceived', static function (): void {
        it('captures trigger payload information', function (): void {
            $workflowId = WorkflowId::generate();
            $definitionKey = DefinitionKey::fromString('test-workflow');
            $definitionVersion = DefinitionVersion::fromString('1.0.0');
            $triggerPayloadId = TriggerPayloadId::generate();
            $triggerPayload = TriggerPayload::fromArray(['approved' => true, 'comment' => 'LGTM']);
            $occurredAt = CarbonImmutable::now();

            $event = new TriggerReceived(
                workflowId: $workflowId,
                definitionKey: $definitionKey,
                definitionVersion: $definitionVersion,
                triggerKey: 'approval',
                payloadId: $triggerPayloadId,
                payload: $triggerPayload,
                sourceIp: '192.168.1.1',
                sourceIdentifier: 'user-123',
                occurredAt: $occurredAt,
            );

            expect($event->workflowId)->toBe($workflowId)
                ->and($event->triggerKey)->toBe('approval')
                ->and($event->payloadId)->toBe($triggerPayloadId)
                ->and($event->payload->get('approved'))->toBeTrue()
                ->and($event->sourceIp)->toBe('192.168.1.1')
                ->and($event->sourceIdentifier)->toBe('user-123');
        });
    });

    describe('TriggerValidationFailed', static function (): void {
        it('captures validation failure information', function (): void {
            $workflowId = WorkflowId::generate();
            $definitionKey = DefinitionKey::fromString('test-workflow');
            $definitionVersion = DefinitionVersion::fromString('1.0.0');
            $triggerPayload = TriggerPayload::fromArray(['approved' => false]);
            $occurredAt = CarbonImmutable::now();

            $event = new TriggerValidationFailed(
                workflowId: $workflowId,
                definitionKey: $definitionKey,
                definitionVersion: $definitionVersion,
                triggerKey: 'approval',
                payload: $triggerPayload,
                failureReason: 'Approval must be true',
                sourceIp: '192.168.1.1',
                sourceIdentifier: 'user-123',
                occurredAt: $occurredAt,
            );

            expect($event->workflowId)->toBe($workflowId)
                ->and($event->failureReason)->toBe('Approval must be true');
        });
    });

    describe('TriggerTimedOut', static function (): void {
        it('captures timeout information with policy', function (): void {
            $workflowId = WorkflowId::generate();
            $definitionKey = DefinitionKey::fromString('test-workflow');
            $definitionVersion = DefinitionVersion::fromString('1.0.0');
            $timeoutAt = CarbonImmutable::now()->subMinute();
            $occurredAt = CarbonImmutable::now();

            $event = new TriggerTimedOut(
                workflowId: $workflowId,
                definitionKey: $definitionKey,
                definitionVersion: $definitionVersion,
                triggerKey: 'approval',
                appliedPolicy: TriggerTimeoutPolicy::FailWorkflow,
                timeoutAt: $timeoutAt,
                occurredAt: $occurredAt,
            );

            expect($event->workflowId)->toBe($workflowId)
                ->and($event->triggerKey)->toBe('approval')
                ->and($event->appliedPolicy)->toBe(TriggerTimeoutPolicy::FailWorkflow)
                ->and($event->timeoutAt)->toBe($timeoutAt);
        });
    });

    describe('WorkflowAutoResumed', static function (): void {
        it('captures auto-resume information', function (): void {
            $workflowId = WorkflowId::generate();
            $definitionKey = DefinitionKey::fromString('test-workflow');
            $definitionVersion = DefinitionVersion::fromString('1.0.0');
            $scheduledAt = CarbonImmutable::now()->subHour();
            $occurredAt = CarbonImmutable::now();

            $event = new WorkflowAutoResumed(
                workflowId: $workflowId,
                definitionKey: $definitionKey,
                definitionVersion: $definitionVersion,
                triggerKey: 'cooling-off',
                scheduledAt: $scheduledAt,
                occurredAt: $occurredAt,
            );

            expect($event->workflowId)->toBe($workflowId)
                ->and($event->triggerKey)->toBe('cooling-off')
                ->and($event->scheduledAt)->toBe($scheduledAt);
        });
    });
});
