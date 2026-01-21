<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Definition\Steps\SingleJobStepDefinition;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;
use Maestro\Workflow\ValueObjects\StepKey;

describe('SingleJobStepDefinition', static function (): void {
    describe('create', static function (): void {
        it('creates definition with required parameters', function (): void {
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                stepKey: StepKey::fromString('test-step'),
                displayName: 'Test Step',
                jobClass: TestJob::class,
            );

            expect($singleJobStepDefinition->key()->toString())->toBe('test-step');
            expect($singleJobStepDefinition->displayName())->toBe('Test Step');
            expect($singleJobStepDefinition->jobClass())->toBe(TestJob::class);
            expect($singleJobStepDefinition->requires())->toBe([]);
            expect($singleJobStepDefinition->produces())->toBeNull();
            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::FailWorkflow);
        });

        it('creates definition with all parameters', function (): void {
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                stepKey: StepKey::fromString('process'),
                displayName: 'Process Data',
                jobClass: TestJob::class,
                requires: [TestOutput::class],
                produces: AnotherOutput::class,
                failurePolicy: FailurePolicy::RetryStep,
                retryConfiguration: RetryConfiguration::create(maxAttempts: 5),
                stepTimeout: StepTimeout::create(300, 60),
                queueConfiguration: QueueConfiguration::onQueue('high'),
            );

            expect($singleJobStepDefinition->requires())->toBe([TestOutput::class]);
            expect($singleJobStepDefinition->produces())->toBe(AnotherOutput::class);
            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::RetryStep);
            expect($singleJobStepDefinition->retryConfiguration()->maxAttempts)->toBe(5);
            expect($singleJobStepDefinition->timeout()->stepTimeoutSeconds)->toBe(300);
            expect($singleJobStepDefinition->queueConfiguration()->queue)->toBe('high');
        });
    });

    describe('hasRequirements', static function (): void {
        it('returns true when step has requirements', function (): void {
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                stepKey: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
                requires: [TestOutput::class],
            );

            expect($singleJobStepDefinition->hasRequirements())->toBeTrue();
        });

        it('returns false when step has no requirements', function (): void {
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                stepKey: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
            );

            expect($singleJobStepDefinition->hasRequirements())->toBeFalse();
        });
    });

    describe('producesOutput', static function (): void {
        it('returns true when step produces output', function (): void {
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                stepKey: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
                produces: TestOutput::class,
            );

            expect($singleJobStepDefinition->producesOutput())->toBeTrue();
        });

        it('returns false when step produces no output', function (): void {
            $singleJobStepDefinition = SingleJobStepDefinition::create(
                stepKey: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
            );

            expect($singleJobStepDefinition->producesOutput())->toBeFalse();
        });
    });
});
