<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\TriggerResult;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('TriggerResult', function (): void {
    beforeEach(function (): void {
        $this->workflow = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
        );
    });

    describe('success', function (): void {
        it('creates successful result', function (): void {
            $triggerResult = TriggerResult::success($this->workflow, 'webhook');

            expect($triggerResult->isSuccess())->toBeTrue();
            expect($triggerResult->workflow())->toBe($this->workflow);
            expect($triggerResult->triggerType())->toBe('webhook');
            expect($triggerResult->failureReason())->toBeNull();
        });
    });

    describe('workflowTerminal', function (): void {
        it('creates terminal state result', function (): void {
            $this->workflow->start(StepKey::fromString('step-1'));
            $this->workflow->succeed();

            $triggerResult = TriggerResult::workflowTerminal($this->workflow);

            expect($triggerResult->isSuccess())->toBeFalse();
            expect($triggerResult->workflow())->toBe($this->workflow);
            expect($triggerResult->triggerType())->toBeNull();
            expect($triggerResult->failureReason())->toContain('succeeded');
        });
    });

    describe('transitionFailed', function (): void {
        it('creates transition failed result', function (): void {
            $triggerResult = TriggerResult::transitionFailed($this->workflow, 'Invalid state transition');

            expect($triggerResult->isSuccess())->toBeFalse();
            expect($triggerResult->workflow())->toBe($this->workflow);
            expect($triggerResult->triggerType())->toBeNull();
            expect($triggerResult->failureReason())->toBe('Invalid state transition');
        });
    });
});
