<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\StepTimeout;

describe('StepTimeout', static function (): void {
    describe('create', static function (): void {
        it('creates timeout with both values', function (): void {
            $timeout = StepTimeout::create(300, 60);

            expect($timeout->stepTimeoutSeconds)->toBe(300);
            expect($timeout->jobTimeoutSeconds)->toBe(60);
        });

        it('creates timeout with null values', function (): void {
            $timeout = StepTimeout::create(null, null);

            expect($timeout->stepTimeoutSeconds)->toBeNull();
            expect($timeout->jobTimeoutSeconds)->toBeNull();
        });
    });

    describe('none', static function (): void {
        it('creates timeout with no values set', function (): void {
            $timeout = StepTimeout::none();

            expect($timeout->hasStepTimeout())->toBeFalse();
            expect($timeout->hasJobTimeout())->toBeFalse();
            expect($timeout->hasAnyTimeout())->toBeFalse();
        });
    });

    describe('stepOnly', static function (): void {
        it('creates timeout with only step timeout', function (): void {
            $timeout = StepTimeout::stepOnly(300);

            expect($timeout->stepTimeoutSeconds)->toBe(300);
            expect($timeout->jobTimeoutSeconds)->toBeNull();
            expect($timeout->hasStepTimeout())->toBeTrue();
            expect($timeout->hasJobTimeout())->toBeFalse();
        });
    });

    describe('jobOnly', static function (): void {
        it('creates timeout with only job timeout', function (): void {
            $timeout = StepTimeout::jobOnly(60);

            expect($timeout->stepTimeoutSeconds)->toBeNull();
            expect($timeout->jobTimeoutSeconds)->toBe(60);
            expect($timeout->hasStepTimeout())->toBeFalse();
            expect($timeout->hasJobTimeout())->toBeTrue();
        });
    });

    describe('withStepTimeout', static function (): void {
        it('returns new instance with step timeout', function (): void {
            $original = StepTimeout::none();
            $updated = $original->withStepTimeout(300);

            expect($updated->stepTimeoutSeconds)->toBe(300);
            expect($original->stepTimeoutSeconds)->toBeNull();
        });
    });

    describe('withJobTimeout', static function (): void {
        it('returns new instance with job timeout', function (): void {
            $original = StepTimeout::none();
            $updated = $original->withJobTimeout(60);

            expect($updated->jobTimeoutSeconds)->toBe(60);
            expect($original->jobTimeoutSeconds)->toBeNull();
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal timeouts', function (): void {
            $a = StepTimeout::create(300, 60);
            $b = StepTimeout::create(300, 60);

            expect($a->equals($b))->toBeTrue();
        });

        it('returns false for different timeouts', function (): void {
            $a = StepTimeout::create(300, 60);
            $b = StepTimeout::create(300, 90);

            expect($a->equals($b))->toBeFalse();
        });
    });
});
