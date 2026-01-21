<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\RetryScope;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;

describe('SingleJobStepBuilder', static function (): void {
    describe('build', static function (): void {
        it('builds step with minimal configuration', function (): void {
            $step = SingleJobStepBuilder::create('validate')
                ->job(TestJob::class)
                ->build();

            expect($step->key()->toString())->toBe('validate');
            expect($step->displayName())->toBe('validate');
            expect($step->jobClass())->toBe(TestJob::class);
        });

        it('builds step with full configuration', function (): void {
            $step = SingleJobStepBuilder::create('process')
                ->displayName('Process Data')
                ->job(TestJob::class)
                ->requires(TestOutput::class)
                ->produces(AnotherOutput::class)
                ->failWorkflow()
                ->retry(5, 30, 2.0, 1800, RetryScope::FailedOnly)
                ->timeout(300, 60)
                ->onQueue('high')
                ->onConnection('redis')
                ->delay(10)
                ->build();

            expect($step->displayName())->toBe('Process Data');
            expect($step->requires())->toBe([TestOutput::class]);
            expect($step->produces())->toBe(AnotherOutput::class);
            expect($step->failurePolicy())->toBe(FailurePolicy::FailWorkflow);
            expect($step->retryConfiguration()->maxAttempts)->toBe(5);
            expect($step->retryConfiguration()->scope)->toBe(RetryScope::FailedOnly);
            expect($step->timeout()->stepTimeoutSeconds)->toBe(300);
            expect($step->timeout()->jobTimeoutSeconds)->toBe(60);
            expect($step->queueConfiguration()->queue)->toBe('high');
            expect($step->queueConfiguration()->connection)->toBe('redis');
            expect($step->queueConfiguration()->delaySeconds)->toBe(10);
        });
    });

    describe('failure policy shortcuts', static function (): void {
        it('sets failWorkflow policy', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->failWorkflow()
                ->build();

            expect($step->failurePolicy())->toBe(FailurePolicy::FailWorkflow);
        });

        it('sets pauseWorkflow policy', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->pauseWorkflow()
                ->build();

            expect($step->failurePolicy())->toBe(FailurePolicy::PauseWorkflow);
        });

        it('sets retryStep policy', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->retryStep()
                ->build();

            expect($step->failurePolicy())->toBe(FailurePolicy::RetryStep);
        });

        it('sets skipStep policy', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->skipStep()
                ->build();

            expect($step->failurePolicy())->toBe(FailurePolicy::SkipStep);
        });
    });

    describe('noRetry', static function (): void {
        it('creates step with no retry', function (): void {
            $step = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->noRetry()
                ->build();

            expect($step->retryConfiguration()->allowsRetry())->toBeFalse();
        });
    });
});
