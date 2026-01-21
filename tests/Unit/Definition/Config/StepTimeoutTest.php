<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\StepTimeout;

describe('StepTimeout', static function (): void {
    describe('create', static function (): void {
        it('creates timeout with both values', function (): void {
            $stepTimeout = StepTimeout::create(300, 60);

            expect($stepTimeout->stepTimeoutSeconds)->toBe(300);
            expect($stepTimeout->jobTimeoutSeconds)->toBe(60);
        });

        it('creates timeout with null values', function (): void {
            $stepTimeout = StepTimeout::create();

            expect($stepTimeout->stepTimeoutSeconds)->toBeNull();
            expect($stepTimeout->jobTimeoutSeconds)->toBeNull();
        });
    });

    describe('none', static function (): void {
        it('creates timeout with no values set', function (): void {
            $stepTimeout = StepTimeout::none();

            expect($stepTimeout->hasStepTimeout())->toBeFalse();
            expect($stepTimeout->hasJobTimeout())->toBeFalse();
            expect($stepTimeout->hasAnyTimeout())->toBeFalse();
        });
    });

    describe('stepOnly', static function (): void {
        it('creates timeout with only step timeout', function (): void {
            $stepTimeout = StepTimeout::stepOnly(300);

            expect($stepTimeout->stepTimeoutSeconds)->toBe(300);
            expect($stepTimeout->jobTimeoutSeconds)->toBeNull();
            expect($stepTimeout->hasStepTimeout())->toBeTrue();
            expect($stepTimeout->hasJobTimeout())->toBeFalse();
        });
    });

    describe('jobOnly', static function (): void {
        it('creates timeout with only job timeout', function (): void {
            $stepTimeout = StepTimeout::jobOnly(60);

            expect($stepTimeout->stepTimeoutSeconds)->toBeNull();
            expect($stepTimeout->jobTimeoutSeconds)->toBe(60);
            expect($stepTimeout->hasStepTimeout())->toBeFalse();
            expect($stepTimeout->hasJobTimeout())->toBeTrue();
        });
    });

    describe('withStepTimeout', static function (): void {
        it('returns new instance with step timeout', function (): void {
            $stepTimeout = StepTimeout::none();
            $updated = $stepTimeout->withStepTimeout(300);

            expect($updated->stepTimeoutSeconds)->toBe(300);
            expect($stepTimeout->stepTimeoutSeconds)->toBeNull();
        });
    });

    describe('withJobTimeout', static function (): void {
        it('returns new instance with job timeout', function (): void {
            $stepTimeout = StepTimeout::none();
            $updated = $stepTimeout->withJobTimeout(60);

            expect($updated->jobTimeoutSeconds)->toBe(60);
            expect($stepTimeout->jobTimeoutSeconds)->toBeNull();
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal timeouts', function (): void {
            $stepTimeout = StepTimeout::create(300, 60);
            $b = StepTimeout::create(300, 60);

            expect($stepTimeout->equals($b))->toBeTrue();
        });

        it('returns false for different timeouts', function (): void {
            $stepTimeout = StepTimeout::create(300, 60);
            $b = StepTimeout::create(300, 90);

            expect($stepTimeout->equals($b))->toBeFalse();
        });
    });
});
