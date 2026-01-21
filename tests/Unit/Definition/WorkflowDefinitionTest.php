<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\Tests\Fixtures\TestContextLoader;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

describe('WorkflowDefinition', static function (): void {
    beforeEach(function (): void {
        $singleJobStepDefinition = SingleJobStepBuilder::create('step-one')
            ->job(TestJob::class)
            ->produces(TestOutput::class)
            ->build();

        $step2 = SingleJobStepBuilder::create('step-two')
            ->job(TestJob::class)
            ->requires(TestOutput::class)
            ->produces(AnotherOutput::class)
            ->build();

        $step3 = SingleJobStepBuilder::create('step-three')
            ->job(TestJob::class)
            ->requires(TestOutput::class, AnotherOutput::class)
            ->build();

        $this->definition = WorkflowDefinition::create(
            displayName: 'Test Workflow',
            contextLoaderClass: TestContextLoader::class,
            key: DefinitionKey::fromString('test-workflow'),
            version: DefinitionVersion::fromString('1.0.0'),
            steps: StepCollection::fromArray([$singleJobStepDefinition, $step2, $step3]),
        );
    });

    describe('basic accessors', static function (): void {
        it('returns the key', function (): void {
            expect($this->definition->key()->toString())->toBe('test-workflow');
        });

        it('returns the version', function (): void {
            expect($this->definition->version()->toString())->toBe('1.0.0');
        });

        it('returns the display name', function (): void {
            expect($this->definition->displayName())->toBe('Test Workflow');
        });

        it('returns the step count', function (): void {
            expect($this->definition->stepCount())->toBe(3);
        });

        it('returns the context loader class', function (): void {
            expect($this->definition->contextLoaderClass())->toBe(TestContextLoader::class);
        });

        it('returns the identifier', function (): void {
            expect($this->definition->identifier())->toBe('test-workflow:1.0.0');
        });
    });

    describe('hasContextLoader', static function (): void {
        it('returns true when context loader is set', function (): void {
            expect($this->definition->hasContextLoader())->toBeTrue();
        });

        it('returns false when context loader is null', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test',
                key: DefinitionKey::fromString('test'),
                version: DefinitionVersion::initial(),
                steps: StepCollection::empty(),
            );

            expect($workflowDefinition->hasContextLoader())->toBeFalse();
        });
    });

    describe('getStep', static function (): void {
        it('returns step by key', function (): void {
            $step = $this->definition->getStep(StepKey::fromString('step-two'));

            expect($step)->not->toBeNull();
            expect($step->key()->toString())->toBe('step-two');
        });

        it('returns null for non-existent key', function (): void {
            $step = $this->definition->getStep(StepKey::fromString('non-existent'));

            expect($step)->toBeNull();
        });
    });

    describe('hasStep', static function (): void {
        it('returns true for existing step', function (): void {
            expect($this->definition->hasStep(StepKey::fromString('step-one')))->toBeTrue();
        });

        it('returns false for non-existent step', function (): void {
            expect($this->definition->hasStep(StepKey::fromString('non-existent')))->toBeFalse();
        });
    });

    describe('getFirstStep', static function (): void {
        it('returns first step', function (): void {
            $step = $this->definition->getFirstStep();

            expect($step)->not->toBeNull();
            expect($step->key()->toString())->toBe('step-one');
        });
    });

    describe('getNextStep', static function (): void {
        it('returns next step', function (): void {
            $step = $this->definition->getNextStep(StepKey::fromString('step-one'));

            expect($step)->not->toBeNull();
            expect($step->key()->toString())->toBe('step-two');
        });

        it('returns null for last step', function (): void {
            $step = $this->definition->getNextStep(StepKey::fromString('step-three'));

            expect($step)->toBeNull();
        });

        it('returns null for non-existent step', function (): void {
            $step = $this->definition->getNextStep(StepKey::fromString('non-existent'));

            expect($step)->toBeNull();
        });
    });

    describe('isLastStep', static function (): void {
        it('returns true for last step', function (): void {
            expect($this->definition->isLastStep(StepKey::fromString('step-three')))->toBeTrue();
        });

        it('returns false for non-last step', function (): void {
            expect($this->definition->isLastStep(StepKey::fromString('step-one')))->toBeFalse();
        });
    });

    describe('getAvailableOutputsBeforeStep', static function (): void {
        it('returns empty for first step', function (): void {
            $outputs = $this->definition->getAvailableOutputsBeforeStep(StepKey::fromString('step-one'));

            expect($outputs)->toBe([]);
        });

        it('returns outputs from prior steps', function (): void {
            $outputs = $this->definition->getAvailableOutputsBeforeStep(StepKey::fromString('step-three'));

            expect($outputs)->toBe([TestOutput::class, AnotherOutput::class]);
        });
    });

    describe('getAvailableOutputsAfterStep', static function (): void {
        it('returns output including current step', function (): void {
            $outputs = $this->definition->getAvailableOutputsAfterStep(StepKey::fromString('step-one'));

            expect($outputs)->toBe([TestOutput::class]);
        });

        it('returns all outputs after last step', function (): void {
            $outputs = $this->definition->getAvailableOutputsAfterStep(StepKey::fromString('step-three'));

            expect($outputs)->toBe([TestOutput::class, AnotherOutput::class]);
        });
    });

    describe('areRequirementsSatisfied', static function (): void {
        it('returns true for first step with no requirements', function (): void {
            expect($this->definition->areRequirementsSatisfied(StepKey::fromString('step-one')))->toBeTrue();
        });

        it('returns true when all requirements are met', function (): void {
            expect($this->definition->areRequirementsSatisfied(StepKey::fromString('step-two')))->toBeTrue();
        });

        it('returns false for non-existent step', function (): void {
            expect($this->definition->areRequirementsSatisfied(StepKey::fromString('non-existent')))->toBeFalse();
        });
    });
});
