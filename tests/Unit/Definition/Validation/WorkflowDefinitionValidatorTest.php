<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\Validation\WorkflowDefinitionValidator;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\Tests\Fixtures\TestContextLoader;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('WorkflowDefinitionValidator', static function (): void {
    beforeEach(function (): void {
        $this->validator = new WorkflowDefinitionValidator(checkClassExistence: true);
    });

    describe('validate empty workflow', static function (): void {
        it('returns error for empty workflow', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Empty',
                key: DefinitionKey::fromString('empty'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::empty(),
            );

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('EMPTY_WORKFLOW');
        });
    });

    describe('validate duplicate step keys', static function (): void {
        it('returns error for duplicate step keys', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('duplicate')
                ->job(TestJob::class)
                ->build();

            $step2 = SingleJobStepBuilder::create('duplicate')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::fromArray([$singleJobStepDefinition, $step2]),
            );

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('DUPLICATE_STEP_KEY');
        });
    });

    describe('validate output dependencies', static function (): void {
        it('passes when dependencies are satisfied', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('valid')
                ->singleJob('step-one', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->singleJob('step-two', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class)
                    ->produces(AnotherOutput::class))
                ->singleJob('step-three', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class, AnotherOutput::class))
                ->build();

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isValid())->toBeTrue();
        });

        it('returns error for missing required output', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('invalid')
                ->singleJob('step-one', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class))
                ->build();

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('MISSING_REQUIRED_OUTPUT');
        });
    });

    describe('validate class existence', static function (): void {
        it('returns error for non-existent job class', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job('NonExistent\\JobClass')
                ->build();

            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::fromArray([$singleJobStepDefinition]),
            );

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('JOB_CLASS_NOT_FOUND');
        });

        it('returns error for non-existent output class in requires', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->requires('NonExistent\\OutputClass')
                ->build();

            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::fromArray([$singleJobStepDefinition]),
            );

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('OUTPUT_CLASS_NOT_FOUND');
        });

        it('returns error for non-existent output class in produces', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->produces('NonExistent\\OutputClass')
                ->build();

            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::fromArray([$singleJobStepDefinition]),
            );

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('OUTPUT_CLASS_NOT_FOUND');
        });

        it('returns error for non-existent context loader', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->build();

            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                contextLoaderClass: 'NonExistent\\ContextLoader',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::fromArray([$singleJobStepDefinition]),
            );

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('CONTEXT_LOADER_NOT_FOUND');
        });
    });

    describe('skip class existence checks', static function (): void {
        it('skips class existence checks when disabled', function (): void {
            $validator = new WorkflowDefinitionValidator(checkClassExistence: false);

            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job('NonExistent\\JobClass')
                ->build();

            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::fromArray([$singleJobStepDefinition]),
            );

            $validationResult = $validator->validate($workflowDefinition);

            expect($validationResult->isValid())->toBeTrue();
        });
    });

    describe('valid workflow definition', static function (): void {
        it('passes validation for complete valid definition', function (): void {
            $workflowDefinition = WorkflowDefinitionBuilder::create('order-fulfillment')
                ->version('1.0.0')
                ->displayName('Order Fulfillment')
                ->contextLoader(TestContextLoader::class)
                ->singleJob('validate', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->singleJob('process', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class)
                    ->produces(AnotherOutput::class))
                ->singleJob('complete', static fn ($b): SingleJobStepBuilder => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class, AnotherOutput::class))
                ->build();

            $result = $this->validator->validate($workflowDefinition);

            expect($result->isValid())->toBeTrue();
            expect($result->errors())->toBe([]);
        });
    });
});
