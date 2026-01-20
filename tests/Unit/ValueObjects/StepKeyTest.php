<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\ValueObjects\StepKey;

describe('StepKey', static function (): void {
    it('creates from valid string', function (): void {
        $stepKey = StepKey::fromString('validate-order');

        expect($stepKey->value)->toBe('validate-order');
    });

    it('accepts lowercase letters only', function (): void {
        $stepKey = StepKey::fromString('process');

        expect($stepKey->value)->toBe('process');
    });

    it('accepts numbers after first character', function (): void {
        $stepKey = StepKey::fromString('step1');

        expect($stepKey->value)->toBe('step1');
    });

    it('accepts hyphens', function (): void {
        $stepKey = StepKey::fromString('my-step-name');

        expect($stepKey->value)->toBe('my-step-name');
    });

    it('throws on empty string', function (): void {
        expect(static fn (): StepKey => StepKey::fromString(''))
            ->toThrow(InvalidStepKeyException::class, 'cannot be empty');
    });

    it('throws on whitespace only', function (): void {
        expect(static fn (): StepKey => StepKey::fromString('   '))
            ->toThrow(InvalidStepKeyException::class, 'cannot be empty');
    });

    it('throws on uppercase letters', function (): void {
        expect(static fn (): StepKey => StepKey::fromString('ValidateOrder'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('throws on starting with number', function (): void {
        expect(static fn (): StepKey => StepKey::fromString('1step'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('throws on special characters', function (): void {
        expect(static fn (): StepKey => StepKey::fromString('step_name'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('compares equality correctly', function (): void {
        $stepKey = StepKey::fromString('validate-order');
        $key2 = StepKey::fromString('validate-order');
        $key3 = StepKey::fromString('process-payment');

        expect($stepKey->equals($key2))->toBeTrue();
        expect($stepKey->equals($key3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $stepKey = StepKey::fromString('validate-order');

        expect($stepKey->toString())->toBe('validate-order');
        expect((string) $stepKey)->toBe('validate-order');
    });

    it('is readonly', function (): void {
        expect(StepKey::class)->toBeImmutable();
    });
});
