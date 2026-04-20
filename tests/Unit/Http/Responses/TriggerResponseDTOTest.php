<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Orchestration\TriggerResult;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Http\Responses\TriggerResponseDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('TriggerResponseDTO', function () {
    describe('fromTriggerResult', function () {
        it('creates DTO from successful trigger result', function () {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('order-processing'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $workflow->start(StepKey::fromString('step-1'));

            $result = TriggerResult::success($workflow, 'payment_webhook');

            $dto = TriggerResponseDTO::fromTriggerResult($result);

            expect($dto->success)->toBeTrue()
                ->and($dto->workflow->id)->toBe($workflow->id->value)
                ->and($dto->triggerType)->toBe('payment_webhook')
                ->and($dto->failureReason)->toBeNull();
        });

        it('creates DTO from failed trigger result with terminal workflow', function () {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('order-processing'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $workflow->start(StepKey::fromString('step-1'));
            $workflow->succeed();

            $result = TriggerResult::workflowTerminal($workflow);

            $dto = TriggerResponseDTO::fromTriggerResult($result);

            expect($dto->success)->toBeFalse()
                ->and($dto->failureReason)->toContain('terminal state');
        });

        it('creates DTO from failed trigger result with transition failure', function () {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('order-processing'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $workflow->start(StepKey::fromString('step-1'));

            $result = TriggerResult::transitionFailed($workflow, 'Trigger type mismatch');

            $dto = TriggerResponseDTO::fromTriggerResult($result);

            expect($dto->success)->toBeFalse()
                ->and($dto->failureReason)->toBe('Trigger type mismatch')
                ->and($dto->triggerType)->toBeNull();
        });
    });

    describe('toArray', function () {
        it('converts successful DTO to array', function () {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('order-processing'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $workflow->start(StepKey::fromString('step-1'));

            $result = TriggerResult::success($workflow, 'payment_webhook');
            $dto = TriggerResponseDTO::fromTriggerResult($result);

            $array = $dto->toArray();

            expect($array)->toHaveKey('success')
                ->and($array['success'])->toBeTrue()
                ->and($array)->toHaveKey('workflow')
                ->and($array['workflow'])->toBeArray()
                ->and($array['workflow']['id'])->toBe($workflow->id->value)
                ->and($array)->toHaveKey('trigger_type')
                ->and($array['trigger_type'])->toBe('payment_webhook');
        });

        it('converts failed DTO to array', function () {
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('order-processing'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $workflow->start(StepKey::fromString('step-1'));

            $result = TriggerResult::transitionFailed($workflow, 'Error message');
            $dto = TriggerResponseDTO::fromTriggerResult($result);

            $array = $dto->toArray();

            expect($array)->toHaveKey('success')
                ->and($array['success'])->toBeFalse()
                ->and($array)->toHaveKey('failure_reason')
                ->and($array['failure_reason'])->toBe('Error message');
        });
    });
});
