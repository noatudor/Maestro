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
            $singleJobStepDefinition = SingleJobStepBuilder::create('validate')
                ->job(TestJob::class)
                ->build();

            expect($singleJobStepDefinition->key()->toString())->toBe('validate');
            expect($singleJobStepDefinition->displayName())->toBe('validate');
            expect($singleJobStepDefinition->jobClass())->toBe(TestJob::class);
        });

        it('builds step with full configuration', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('process')
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

            expect($singleJobStepDefinition->displayName())->toBe('Process Data');
            expect($singleJobStepDefinition->requires())->toBe([TestOutput::class]);
            expect($singleJobStepDefinition->produces())->toBe(AnotherOutput::class);
            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::FailWorkflow);
            expect($singleJobStepDefinition->retryConfiguration()->maxAttempts)->toBe(5);
            expect($singleJobStepDefinition->retryConfiguration()->scope)->toBe(RetryScope::FailedOnly);
            expect($singleJobStepDefinition->timeout()->stepTimeoutSeconds)->toBe(300);
            expect($singleJobStepDefinition->timeout()->jobTimeoutSeconds)->toBe(60);
            expect($singleJobStepDefinition->queueConfiguration()->queue)->toBe('high');
            expect($singleJobStepDefinition->queueConfiguration()->connection)->toBe('redis');
            expect($singleJobStepDefinition->queueConfiguration()->delaySeconds)->toBe(10);
        });
    });

    describe('failure policy shortcuts', static function (): void {
        it('sets failWorkflow policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->failWorkflow()
                ->build();

            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::FailWorkflow);
        });

        it('sets pauseWorkflow policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->pauseWorkflow()
                ->build();

            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::PauseWorkflow);
        });

        it('sets retryStep policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->retryStep()
                ->build();

            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::RetryStep);
        });

        it('sets skipStep policy', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->skipStep()
                ->build();

            expect($singleJobStepDefinition->failurePolicy())->toBe(FailurePolicy::SkipStep);
        });
    });

    describe('noRetry', static function (): void {
        it('creates step with no retry', function (): void {
            $singleJobStepDefinition = SingleJobStepBuilder::create('test')
                ->job(TestJob::class)
                ->noRetry()
                ->build();

            expect($singleJobStepDefinition->retryConfiguration()->allowsRetry())->toBeFalse();
        });
    });
});
