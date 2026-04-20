<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Http\Responses\WorkflowDetailDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('WorkflowDetailDTO', function () {
    it('creates DTO from workflow instance with empty collections', function () {
        $workflow = WorkflowInstance::create(
            DefinitionKey::fromString('order-processing'),
            DefinitionVersion::fromString('1.0.0'),
        );
        $workflow->start(StepKey::fromString('validate-order'));

        $dto = WorkflowDetailDTO::create(
            $workflow,
            new StepRunCollection([]),
            new JobRecordCollection([]),
        );

        expect($dto->workflow->definitionKey)->toBe('order-processing')
            ->and($dto->workflow->definitionVersion)->toBe('1.0.0')
            ->and($dto->steps)->toBe([])
            ->and($dto->jobs)->toBe([]);
    });

    it('converts to array with proper structure', function () {
        $workflow = WorkflowInstance::create(
            DefinitionKey::fromString('order-processing'),
            DefinitionVersion::fromString('1.0.0'),
        );
        $workflow->start(StepKey::fromString('validate-order'));

        $dto = WorkflowDetailDTO::create(
            $workflow,
            new StepRunCollection([]),
            new JobRecordCollection([]),
        );

        $array = $dto->toArray();

        expect($array)->toHaveKey('workflow')
            ->and($array)->toHaveKey('steps')
            ->and($array)->toHaveKey('jobs')
            ->and($array['workflow'])->toHaveKey('id')
            ->and($array['workflow'])->toHaveKey('definition_key')
            ->and($array['workflow']['definition_key'])->toBe('order-processing')
            ->and($array['steps'])->toBe([])
            ->and($array['jobs'])->toBe([]);
    });

    it('includes steps in the output', function () {
        $workflow = WorkflowInstance::create(
            DefinitionKey::fromString('order-processing'),
            DefinitionVersion::fromString('1.0.0'),
        );
        $workflow->start(StepKey::fromString('validate-order'));

        $stepRun = StepRun::create(
            $workflow->id,
            StepKey::fromString('validate-order'),
        );
        $stepRun->start();
        $stepRun->succeed();

        $dto = WorkflowDetailDTO::create(
            $workflow,
            new StepRunCollection([$stepRun]),
            new JobRecordCollection([]),
        );

        expect($dto->steps)->toHaveCount(1)
            ->and($dto->steps[0]->stepKey)->toBe('validate-order');

        $array = $dto->toArray();
        expect($array['steps'])->toHaveCount(1)
            ->and($array['steps'][0]['step_key'])->toBe('validate-order');
    });
});
