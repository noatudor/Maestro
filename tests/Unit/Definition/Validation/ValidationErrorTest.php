<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Validation\ValidationError;
use Maestro\Workflow\ValueObjects\StepKey;

describe('ValidationError', static function (): void {
    describe('duplicateStepKey', static function (): void {
        it('creates duplicate step key error', function (): void {
            $stepKey = StepKey::fromString('my-step');
            $validationError = ValidationError::duplicateStepKey($stepKey);

            expect($validationError->code)->toBe('DUPLICATE_STEP_KEY');
            expect($validationError->message)->toBe('Duplicate step key: my-step');
            expect($validationError->stepKey)->toBe($stepKey);
            expect($validationError->hasStepContext())->toBeTrue();
        });
    });

    describe('missingRequiredOutput', static function (): void {
        it('creates missing required output error', function (): void {
            $stepKey = StepKey::fromString('process-step');
            $validationError = ValidationError::missingRequiredOutput($stepKey, 'App\\Outputs\\UserData');

            expect($validationError->code)->toBe('MISSING_REQUIRED_OUTPUT');
            expect($validationError->message)->toContain('process-step');
            expect($validationError->message)->toContain('App\\Outputs\\UserData');
            expect($validationError->stepKey)->toBe($stepKey);
        });
    });

    describe('jobClassNotFound', static function (): void {
        it('creates job class not found error', function (): void {
            $stepKey = StepKey::fromString('execute-step');
            $validationError = ValidationError::jobClassNotFound($stepKey, 'App\\Jobs\\MissingJob');

            expect($validationError->code)->toBe('JOB_CLASS_NOT_FOUND');
            expect($validationError->message)->toContain('execute-step');
            expect($validationError->message)->toContain('App\\Jobs\\MissingJob');
            expect($validationError->stepKey)->toBe($stepKey);
        });
    });

    describe('outputClassNotFound', static function (): void {
        it('creates output class not found error', function (): void {
            $stepKey = StepKey::fromString('output-step');
            $validationError = ValidationError::outputClassNotFound($stepKey, 'App\\Outputs\\MissingOutput');

            expect($validationError->code)->toBe('OUTPUT_CLASS_NOT_FOUND');
            expect($validationError->message)->toContain('output-step');
            expect($validationError->message)->toContain('App\\Outputs\\MissingOutput');
            expect($validationError->stepKey)->toBe($stepKey);
        });
    });

    describe('contextLoaderNotFound', static function (): void {
        it('creates context loader not found error', function (): void {
            $validationError = ValidationError::contextLoaderNotFound('App\\Context\\MissingLoader');

            expect($validationError->code)->toBe('CONTEXT_LOADER_NOT_FOUND');
            expect($validationError->message)->toContain('App\\Context\\MissingLoader');
            expect($validationError->stepKey)->toBeNull();
            expect($validationError->hasStepContext())->toBeFalse();
        });
    });

    describe('emptyWorkflow', static function (): void {
        it('creates empty workflow error', function (): void {
            $validationError = ValidationError::emptyWorkflow();

            expect($validationError->code)->toBe('EMPTY_WORKFLOW');
            expect($validationError->message)->toBe('Workflow definition has no steps');
            expect($validationError->stepKey)->toBeNull();
            expect($validationError->hasStepContext())->toBeFalse();
        });
    });

    describe('custom', static function (): void {
        it('creates custom error without step key', function (): void {
            $validationError = ValidationError::custom('CUSTOM_ERROR', 'Custom error message');

            expect($validationError->code)->toBe('CUSTOM_ERROR');
            expect($validationError->message)->toBe('Custom error message');
            expect($validationError->stepKey)->toBeNull();
            expect($validationError->hasStepContext())->toBeFalse();
        });

        it('creates custom error with step key', function (): void {
            $stepKey = StepKey::fromString('custom-step');
            $validationError = ValidationError::custom('CUSTOM_ERROR', 'Custom error message', $stepKey);

            expect($validationError->code)->toBe('CUSTOM_ERROR');
            expect($validationError->message)->toBe('Custom error message');
            expect($validationError->stepKey)->toBe($stepKey);
            expect($validationError->hasStepContext())->toBeTrue();
        });
    });

    describe('hasStepContext', static function (): void {
        it('returns true when step key is set', function (): void {
            $validationError = ValidationError::duplicateStepKey(StepKey::fromString('test'));

            expect($validationError->hasStepContext())->toBeTrue();
        });

        it('returns false when step key is null', function (): void {
            $validationError = ValidationError::emptyWorkflow();

            expect($validationError->hasStepContext())->toBeFalse();
        });
    });
});
