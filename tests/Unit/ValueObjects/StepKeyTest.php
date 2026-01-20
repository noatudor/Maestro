<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\ValueObjects\StepKey;

describe('StepKey', function () {
    it('creates from valid string', function () {
        $key = StepKey::fromString('validate-order');

        expect($key->value)->toBe('validate-order');
    });

    it('accepts lowercase letters only', function () {
        $key = StepKey::fromString('process');

        expect($key->value)->toBe('process');
    });

    it('accepts numbers after first character', function () {
        $key = StepKey::fromString('step1');

        expect($key->value)->toBe('step1');
    });

    it('accepts hyphens', function () {
        $key = StepKey::fromString('my-step-name');

        expect($key->value)->toBe('my-step-name');
    });

    it('throws on empty string', function () {
        expect(fn () => StepKey::fromString(''))
            ->toThrow(InvalidStepKeyException::class, 'cannot be empty');
    });

    it('throws on whitespace only', function () {
        expect(fn () => StepKey::fromString('   '))
            ->toThrow(InvalidStepKeyException::class, 'cannot be empty');
    });

    it('throws on uppercase letters', function () {
        expect(fn () => StepKey::fromString('ValidateOrder'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('throws on starting with number', function () {
        expect(fn () => StepKey::fromString('1step'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('throws on special characters', function () {
        expect(fn () => StepKey::fromString('step_name'))
            ->toThrow(InvalidStepKeyException::class, 'invalid format');
    });

    it('compares equality correctly', function () {
        $key1 = StepKey::fromString('validate-order');
        $key2 = StepKey::fromString('validate-order');
        $key3 = StepKey::fromString('process-payment');

        expect($key1->equals($key2))->toBeTrue();
        expect($key1->equals($key3))->toBeFalse();
    });

    it('converts to string', function () {
        $key = StepKey::fromString('validate-order');

        expect($key->toString())->toBe('validate-order');
        expect((string) $key)->toBe('validate-order');
    });

    it('is readonly', function () {
        expect(StepKey::class)->toBeImmutable();
    });
});
