<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\ValueObjects\StepKey;

describe('StepKey', function (): void {
    it('creates from valid string', function (): void {
        $key = StepKey::fromString('validate-order');

        expect($key->value)->toBe('validate-order');
    });

    it('accepts lowercase letters only', function (): void {
        $key = StepKey::fromString('process');

        expect($key->value)->toBe('process');
    });

    it('accepts numbers after first character', function (): void {
        $key = StepKey::fromString('step1');

        expect($key->value)->toBe('step1');
    });

    it('accepts hyphens', function (): void {
        $key = StepKey::fromString('my-step-name');

        expect($key->value)->toBe('my-step-name');
    });

    it('throws on empty string', function (): void {
        expect(fn (): StepKey => StepKey::fromString(''))
            ->toThrow(InvalidStepKeyException::class, 'cannot be empty');
    });

    it('throws on whitespace only', function (): void {
        expect(fn (): StepKey => StepKey::fromString('   '))
            ->toThrow(InvalidStepKeyException::class, 'cannot be empty');
    });

    it('throws on uppercase letters', function (): void {
        expect(fn (): StepKey => StepKey::fromString('ValidateOrder'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('throws on starting with number', function (): void {
        expect(fn (): StepKey => StepKey::fromString('1step'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('throws on special characters', function (): void {
        expect(fn (): StepKey => StepKey::fromString('step_name'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('compares equality correctly', function (): void {
        $key1 = StepKey::fromString('validate-order');
        $key2 = StepKey::fromString('validate-order');
        $key3 = StepKey::fromString('process-payment');

        expect($key1->equals($key2))->toBeTrue();
        expect($key1->equals($key3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $key = StepKey::fromString('validate-order');

        expect($key->toString())->toBe('validate-order');
        expect((string) $key)->toBe('validate-order');
    });

    it('is readonly', function (): void {
        expect(StepKey::class)->toBeImmutable();
    });
});
