<?php

declare(strict_types=1);

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\ValueObjects\AbortedPollResult;
use Maestro\Workflow\ValueObjects\CompletedPollResult;
use Maestro\Workflow\ValueObjects\ContinuePollResult;

describe('Poll Results', function () {
    describe('AbortedPollResult', function () {
        it('creates an aborted result', function () {
            $result = AbortedPollResult::create();

            expect($result->isComplete())->toBeFalse()
                ->and($result->shouldContinue())->toBeFalse()
                ->and($result->output())->toBeNull()
                ->and($result->nextIntervalSeconds())->toBeNull();
        });
    });

    describe('CompletedPollResult', function () {
        it('creates a completed result with output', function () {
            $output = Mockery::mock(StepOutput::class);

            $result = CompletedPollResult::withOutput($output);

            expect($result->output())->toBe($output)
                ->and($result->isComplete())->toBeTrue()
                ->and($result->shouldContinue())->toBeFalse()
                ->and($result->nextIntervalSeconds())->toBeNull();
        });

        it('creates a completed result without output', function () {
            $result = CompletedPollResult::withoutOutput();

            expect($result->output())->toBeNull()
                ->and($result->isComplete())->toBeTrue()
                ->and($result->shouldContinue())->toBeFalse();
        });
    });

    describe('ContinuePollResult', function () {
        it('creates a continue result with custom interval', function () {
            $result = ContinuePollResult::afterSeconds(30);

            expect($result->nextIntervalSeconds())->toBe(30)
                ->and($result->shouldContinue())->toBeTrue()
                ->and($result->isComplete())->toBeFalse()
                ->and($result->output())->toBeNull();
        });

        it('creates a continue result at default interval', function () {
            $result = ContinuePollResult::atDefaultInterval();

            expect($result->nextIntervalSeconds())->toBeNull()
                ->and($result->shouldContinue())->toBeTrue()
                ->and($result->isComplete())->toBeFalse();
        });

        it('enforces minimum delay of 1 second', function () {
            $result = ContinuePollResult::afterSeconds(0);

            expect($result->nextIntervalSeconds())->toBe(1);
        });
    });
});
