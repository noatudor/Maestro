<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Validation\ValidationError;
use Maestro\Workflow\Definition\Validation\ValidationResult;
use Maestro\Workflow\Exceptions\InvalidWorkflowDefinitionException;
use Maestro\Workflow\ValueObjects\StepKey;

describe('ValidationResult', static function (): void {
    describe('valid', static function (): void {
        it('creates valid result', function (): void {
            $validationResult = ValidationResult::valid();

            expect($validationResult->isValid())->toBeTrue();
            expect($validationResult->isInvalid())->toBeFalse();
            expect($validationResult->errors())->toBe([]);
            expect($validationResult->errorCount())->toBe(0);
        });
    });

    describe('invalid', static function (): void {
        it('creates invalid result with errors', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $error2 = ValidationError::duplicateStepKey(StepKey::fromString('test'));

            $validationResult = ValidationResult::invalid([$validationError, $error2]);

            expect($validationResult->isValid())->toBeFalse();
            expect($validationResult->isInvalid())->toBeTrue();
            expect($validationResult->errors())->toHaveCount(2);
            expect($validationResult->errorCount())->toBe(2);
        });
    });

    describe('withError', static function (): void {
        it('creates result with single error', function (): void {
            $validationError = ValidationError::emptyWorkflow();

            $validationResult = ValidationResult::withError($validationError);

            expect($validationResult->isInvalid())->toBeTrue();
            expect($validationResult->errors())->toHaveCount(1);
            expect($validationResult->firstError())->toBe($validationError);
        });
    });

    describe('firstError', static function (): void {
        it('returns first error', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $error2 = ValidationError::duplicateStepKey(StepKey::fromString('test'));

            $validationResult = ValidationResult::invalid([$validationError, $error2]);

            expect($validationResult->firstError())->toBe($validationError);
        });

        it('returns null for valid result', function (): void {
            $validationResult = ValidationResult::valid();

            expect($validationResult->firstError())->toBeNull();
        });
    });

    describe('errorMessages', static function (): void {
        it('returns all error messages', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $error2 = ValidationError::duplicateStepKey(StepKey::fromString('test-step'));

            $validationResult = ValidationResult::invalid([$validationError, $error2]);

            $messages = $validationResult->errorMessages();

            expect($messages)->toHaveCount(2);
            expect($messages[0])->toBe('Workflow definition has no steps');
            expect($messages[1])->toBe('Duplicate step key: test-step');
        });
    });

    describe('errorCodes', static function (): void {
        it('returns all error codes', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $error2 = ValidationError::duplicateStepKey(StepKey::fromString('test-step'));

            $validationResult = ValidationResult::invalid([$validationError, $error2]);

            $codes = $validationResult->errorCodes();

            expect($codes)->toBe(['EMPTY_WORKFLOW', 'DUPLICATE_STEP_KEY']);
        });
    });

    describe('merge', static function (): void {
        it('merges two results', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $error2 = ValidationError::duplicateStepKey(StepKey::fromString('test'));

            $validationResult = ValidationResult::withError($validationError);
            $result2 = ValidationResult::withError($error2);

            $merged = $validationResult->merge($result2);

            expect($merged->errorCount())->toBe(2);
            expect($merged->errors())->toContain($validationError);
            expect($merged->errors())->toContain($error2);
        });

        it('merges valid with invalid', function (): void {
            $validationError = ValidationError::emptyWorkflow();

            $valid = ValidationResult::valid();
            $validationResult = ValidationResult::withError($validationError);

            $merged = $valid->merge($validationResult);

            expect($merged->isInvalid())->toBeTrue();
            expect($merged->errorCount())->toBe(1);
        });
    });

    describe('addError', static function (): void {
        it('adds error to result', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $error2 = ValidationError::duplicateStepKey(StepKey::fromString('test'));

            $validationResult = ValidationResult::withError($validationError);
            $newResult = $validationResult->addError($error2);

            expect($newResult->errorCount())->toBe(2);
            expect($validationResult->errorCount())->toBe(1);
        });

        it('adds error to valid result', function (): void {
            $validationError = ValidationError::emptyWorkflow();

            $validationResult = ValidationResult::valid();
            $newResult = $validationResult->addError($validationError);

            expect($newResult->isInvalid())->toBeTrue();
            expect($validationResult->isValid())->toBeTrue();
        });
    });

    describe('throwIfInvalid', static function (): void {
        it('does nothing for valid result', function (): void {
            $validationResult = ValidationResult::valid();

            $validationResult->throwIfInvalid();

            expect(true)->toBeTrue();
        });

        it('throws for invalid result', function (): void {
            $validationError = ValidationError::emptyWorkflow();
            $validationResult = ValidationResult::withError($validationError);

            expect(static fn () => $validationResult->throwIfInvalid())
                ->toThrow(InvalidWorkflowDefinitionException::class);
        });
    });
});
