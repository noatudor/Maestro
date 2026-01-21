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
            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('empty'),
                version: DefinitionVersion::initial(),
                displayName: 'Empty',
                steps: StepCollection::empty(),
            );

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('EMPTY_WORKFLOW');
        });
    });

    describe('validate duplicate step keys', static function (): void {
        it('returns error for duplicate step keys', function (): void {
            $step1 = SingleJobStepBuilder::create('duplicate')
                ->job(TestJob::class)
                ->build();

            $step2 = SingleJobStepBuilder::create('duplicate')
                ->job(TestJob::class)
                ->build();

            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                displayName: 'Test',
                steps: StepCollection::fromArray([$step1, $step2]),
            );

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('DUPLICATE_STEP_KEY');
        });
    });

    describe('validate output dependencies', static function (): void {
        it('passes when dependencies are satisfied', function (): void {
            $definition = WorkflowDefinitionBuilder::create('valid')
                ->singleJob('step-one', fn ($b) => $b
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->singleJob('step-two', fn ($b) => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class)
                    ->produces(AnotherOutput::class))
                ->singleJob('step-three', fn ($b) => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class, AnotherOutput::class))
                ->build();

            $result = $this->validator->validate($definition);

            expect($result->isValid())->toBeTrue();
        });

        it('returns error for missing required output', function (): void {
            $definition = WorkflowDefinitionBuilder::create('invalid')
                ->singleJob('step-one', fn ($b) => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class))
                ->build();

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('MISSING_REQUIRED_OUTPUT');
        });
    });

    describe('validate class existence', static function (): void {
        it('returns error for non-existent job class', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job('NonExistent\\JobClass')
                ->build();

            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                displayName: 'Test',
                steps: StepCollection::fromArray([$step]),
            );

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('JOB_CLASS_NOT_FOUND');
        });

        it('returns error for non-existent output class in requires', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->requires('NonExistent\\OutputClass')
                ->build();

            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                displayName: 'Test',
                steps: StepCollection::fromArray([$step]),
            );

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('OUTPUT_CLASS_NOT_FOUND');
        });

        it('returns error for non-existent output class in produces', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->produces('NonExistent\\OutputClass')
                ->build();

            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                displayName: 'Test',
                steps: StepCollection::fromArray([$step]),
            );

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('OUTPUT_CLASS_NOT_FOUND');
        });

        it('returns error for non-existent context loader', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->build();

            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                displayName: 'Test',
                steps: StepCollection::fromArray([$step]),
                contextLoaderClass: 'NonExistent\\ContextLoader',
            );

            $result = $this->validator->validate($definition);

            expect($result->isInvalid())->toBeTrue();
            expect($result->errorCodes())->toContain('CONTEXT_LOADER_NOT_FOUND');
        });
    });

    describe('skip class existence checks', static function (): void {
        it('skips class existence checks when disabled', function (): void {
            $validator = new WorkflowDefinitionValidator(checkClassExistence: false);

            $step = SingleJobStepBuilder::create('test')
                ->job('NonExistent\\JobClass')
                ->build();

            $definition = WorkflowDefinition::create(
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                displayName: 'Test',
                steps: StepCollection::fromArray([$step]),
            );

            $result = $validator->validate($definition);

            expect($result->isValid())->toBeTrue();
        });
    });

    describe('valid workflow definition', static function (): void {
        it('passes validation for complete valid definition', function (): void {
            $definition = WorkflowDefinitionBuilder::create('order-fulfillment')
                ->version('1.0.0')
                ->displayName('Order Fulfillment')
                ->contextLoader(TestContextLoader::class)
                ->singleJob('validate', fn ($b) => $b
                    ->job(TestJob::class)
                    ->produces(TestOutput::class))
                ->singleJob('process', fn ($b) => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class)
                    ->produces(AnotherOutput::class))
                ->singleJob('complete', fn ($b) => $b
                    ->job(TestJob::class)
                    ->requires(TestOutput::class, AnotherOutput::class))
                ->build();

            $result = $this->validator->validate($definition);

            expect($result->isValid())->toBeTrue();
            expect($result->errors())->toBe([]);
        });
    });
});
