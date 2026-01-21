<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\TriggerResult;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('TriggerResult', function (): void {
    beforeEach(function (): void {
        $this->workflow = WorkflowInstance::create(
            DefinitionKey::fromString('test-workflow'),
            DefinitionVersion::fromString('1.0.0'),
        );
    });

    describe('success', function (): void {
        it('creates successful result', function (): void {
            $result = TriggerResult::success($this->workflow, 'webhook');

            expect($result->isSuccess())->toBeTrue();
            expect($result->workflow())->toBe($this->workflow);
            expect($result->triggerType())->toBe('webhook');
            expect($result->failureReason())->toBeNull();
        });
    });

    describe('workflowTerminal', function (): void {
        it('creates terminal state result', function (): void {
            $this->workflow->start(Maestro\Workflow\ValueObjects\StepKey::fromString('step-1'));
            $this->workflow->succeed();

            $result = TriggerResult::workflowTerminal($this->workflow);

            expect($result->isSuccess())->toBeFalse();
            expect($result->workflow())->toBe($this->workflow);
            expect($result->triggerType())->toBeNull();
            expect($result->failureReason())->toContain('succeeded');
        });
    });

    describe('transitionFailed', function (): void {
        it('creates transition failed result', function (): void {
            $result = TriggerResult::transitionFailed($this->workflow, 'Invalid state transition');

            expect($result->isSuccess())->toBeFalse();
            expect($result->workflow())->toBe($this->workflow);
            expect($result->triggerType())->toBeNull();
            expect($result->failureReason())->toBe('Invalid state transition');
        });
    });
});
