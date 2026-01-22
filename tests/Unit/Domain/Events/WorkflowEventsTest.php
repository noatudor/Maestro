<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Events\WorkflowCancelled;
use Maestro\Workflow\Domain\Events\WorkflowCreated;
use Maestro\Workflow\Domain\Events\WorkflowFailed;
use Maestro\Workflow\Domain\Events\WorkflowPaused;
use Maestro\Workflow\Domain\Events\WorkflowResumed;
use Maestro\Workflow\Domain\Events\WorkflowStarted;
use Maestro\Workflow\Domain\Events\WorkflowSucceeded;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowCreated', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowCreated(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(WorkflowCreated::class)->toBeImmutable();
    });
});

describe('WorkflowStarted', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $firstStepKey = StepKey::fromString('step-one');
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowStarted(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            firstStepKey: $firstStepKey,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->firstStepKey)->toBe($firstStepKey);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(WorkflowStarted::class)->toBeImmutable();
    });
});

describe('WorkflowPaused', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $reason = 'User requested pause';
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowPaused(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            reason: $reason,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->reason)->toBe($reason);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null reason', function (): void {
        $event = new WorkflowPaused(
            workflowId: WorkflowId::generate(),
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::initial(),
            reason: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->reason)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(WorkflowPaused::class)->toBeImmutable();
    });
});

describe('WorkflowResumed', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowResumed(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(WorkflowResumed::class)->toBeImmutable();
    });
});

describe('WorkflowSucceeded', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowSucceeded(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(WorkflowSucceeded::class)->toBeImmutable();
    });
});

describe('WorkflowFailed', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $failureCode = 'STEP_FAILED';
        $failureMessage = 'Step processing failed';
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowFailed(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->failureCode)->toBe($failureCode);
        expect($event->failureMessage)->toBe($failureMessage);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('accepts null failure details', function (): void {
        $event = new WorkflowFailed(
            workflowId: WorkflowId::generate(),
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::initial(),
            failureCode: null,
            failureMessage: null,
            occurredAt: CarbonImmutable::now(),
        );

        expect($event->failureCode)->toBeNull();
        expect($event->failureMessage)->toBeNull();
    });

    it('is readonly', function (): void {
        expect(WorkflowFailed::class)->toBeImmutable();
    });
});

describe('WorkflowCancelled', static function (): void {
    it('stores all properties correctly', function (): void {
        $workflowId = WorkflowId::generate();
        $definitionKey = DefinitionKey::fromString('test-workflow');
        $definitionVersion = DefinitionVersion::initial();
        $occurredAt = CarbonImmutable::now();

        $event = new WorkflowCancelled(
            workflowId: $workflowId,
            definitionKey: $definitionKey,
            definitionVersion: $definitionVersion,
            occurredAt: $occurredAt,
        );

        expect($event->workflowId)->toBe($workflowId);
        expect($event->definitionKey)->toBe($definitionKey);
        expect($event->definitionVersion)->toBe($definitionVersion);
        expect($event->occurredAt)->toBe($occurredAt);
    });

    it('is readonly', function (): void {
        expect(WorkflowCancelled::class)->toBeImmutable();
    });
});
