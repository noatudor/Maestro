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
            $definition = SingleJobStepDefinition::create(
                key: StepKey::fromString('test-step'),
                displayName: 'Test Step',
                jobClass: TestJob::class,
            );

            expect($definition->key()->toString())->toBe('test-step');
            expect($definition->displayName())->toBe('Test Step');
            expect($definition->jobClass())->toBe(TestJob::class);
            expect($definition->requires())->toBe([]);
            expect($definition->produces())->toBeNull();
            expect($definition->failurePolicy())->toBe(FailurePolicy::FailWorkflow);
        });

        it('creates definition with all parameters', function (): void {
            $definition = SingleJobStepDefinition::create(
                key: StepKey::fromString('process'),
                displayName: 'Process Data',
                jobClass: TestJob::class,
                requires: [TestOutput::class],
                produces: AnotherOutput::class,
                failurePolicy: FailurePolicy::RetryStep,
                retryConfiguration: RetryConfiguration::create(maxAttempts: 5),
                timeout: StepTimeout::create(300, 60),
                queueConfiguration: QueueConfiguration::onQueue('high'),
            );

            expect($definition->requires())->toBe([TestOutput::class]);
            expect($definition->produces())->toBe(AnotherOutput::class);
            expect($definition->failurePolicy())->toBe(FailurePolicy::RetryStep);
            expect($definition->retryConfiguration()->maxAttempts)->toBe(5);
            expect($definition->timeout()->stepTimeoutSeconds)->toBe(300);
            expect($definition->queueConfiguration()->queue)->toBe('high');
        });
    });

    describe('hasRequirements', static function (): void {
        it('returns true when step has requirements', function (): void {
            $definition = SingleJobStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
                requires: [TestOutput::class],
            );

            expect($definition->hasRequirements())->toBeTrue();
        });

        it('returns false when step has no requirements', function (): void {
            $definition = SingleJobStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
            );

            expect($definition->hasRequirements())->toBeFalse();
        });
    });

    describe('producesOutput', static function (): void {
        it('returns true when step produces output', function (): void {
            $definition = SingleJobStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
                produces: TestOutput::class,
            );

            expect($definition->producesOutput())->toBeTrue();
        });

        it('returns false when step produces no output', function (): void {
            $definition = SingleJobStepDefinition::create(
                key: StepKey::fromString('test'),
                displayName: 'Test',
                jobClass: TestJob::class,
            );

            expect($definition->producesOutput())->toBeFalse();
        });
    });
});
