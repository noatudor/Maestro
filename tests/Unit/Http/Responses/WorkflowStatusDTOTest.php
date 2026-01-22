<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Http\Responses\WorkflowStatusDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('WorkflowStatusDTO', static function (): void {
    describe('fromWorkflowInstance', static function (): void {
        it('creates dto from pending workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );

            $workflowStatusDTO = WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);

            expect($workflowStatusDTO->id)->toBe($workflowInstance->id->value);
            expect($workflowStatusDTO->definitionKey)->toBe('test-workflow');
            expect($workflowStatusDTO->definitionVersion)->toBe('1.0.0');
            expect($workflowStatusDTO->state)->toBe(WorkflowState::Pending);
            expect($workflowStatusDTO->currentStepKey)->toBeNull();
            expect($workflowStatusDTO->isTerminal)->toBeFalse();
            expect($workflowStatusDTO->isLocked)->toBeFalse();
        });

        it('creates dto from running workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));

            $workflowStatusDTO = WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);

            expect($workflowStatusDTO->state)->toBe(WorkflowState::Running);
            expect($workflowStatusDTO->currentStepKey)->toBe('step-1');
        });

        it('creates dto from paused workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->pause('Waiting for approval');

            $workflowStatusDTO = WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);

            expect($workflowStatusDTO->state)->toBe(WorkflowState::Paused);
            expect($workflowStatusDTO->pausedReason)->toBe('Waiting for approval');
            expect($workflowStatusDTO->pausedAt)->not->toBeNull();
        });

        it('creates dto from failed workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->fail('ERROR_CODE', 'Something went wrong');

            $workflowStatusDTO = WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);

            expect($workflowStatusDTO->state)->toBe(WorkflowState::Failed);
            expect($workflowStatusDTO->failureCode)->toBe('ERROR_CODE');
            expect($workflowStatusDTO->failureMessage)->toBe('Something went wrong');
            expect($workflowStatusDTO->failedAt)->not->toBeNull();
            expect($workflowStatusDTO->isTerminal)->toBeTrue();
        });

        it('creates dto from succeeded workflow', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );
            $workflowInstance->start(StepKey::fromString('step-1'));
            $workflowInstance->succeed();

            $workflowStatusDTO = WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);

            expect($workflowStatusDTO->state)->toBe(WorkflowState::Succeeded);
            expect($workflowStatusDTO->succeededAt)->not->toBeNull();
            expect($workflowStatusDTO->isTerminal)->toBeTrue();
        });
    });

    describe('toArray', static function (): void {
        it('returns array representation', function (): void {
            $workflowInstance = WorkflowInstance::create(
                DefinitionKey::fromString('test-workflow'),
                DefinitionVersion::initial(),
            );

            $workflowStatusDTO = WorkflowStatusDTO::fromWorkflowInstance($workflowInstance);
            $array = $workflowStatusDTO->toArray();

            expect($array)->toBeArray();
            expect($array['id'])->toBe($workflowInstance->id->value);
            expect($array['definition_key'])->toBe('test-workflow');
            expect($array['definition_version'])->toBe('1.0.0');
            expect($array['state'])->toBe('pending');
            expect($array['is_terminal'])->toBeFalse();
            expect($array['is_locked'])->toBeFalse();
        });
    });
});
