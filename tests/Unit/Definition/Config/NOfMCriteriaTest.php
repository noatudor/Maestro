<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\NOfMCriteria;

describe('NOfMCriteria', static function (): void {
    describe('create', static function (): void {
        it('creates criteria with minimum required', function (): void {
            $criteria = NOfMCriteria::create(3);
            expect($criteria->minimumRequired)->toBe(3);
        });

        it('enforces minimum of 1', function (): void {
            $criteria = NOfMCriteria::create(0);
            expect($criteria->minimumRequired)->toBe(1);
        });
    });

    describe('atLeast', static function (): void {
        it('creates criteria with minimum count', function (): void {
            $criteria = NOfMCriteria::atLeast(5);
            expect($criteria->minimumRequired)->toBe(5);
        });
    });

    describe('evaluate', static function (): void {
        it('returns true when total is zero', function (): void {
            $criteria = NOfMCriteria::atLeast(3);
            expect($criteria->evaluate(0, 0))->toBeTrue();
        });

        it('returns true when succeeded meets minimum', function (): void {
            $criteria = NOfMCriteria::atLeast(3);
            expect($criteria->evaluate(3, 5))->toBeTrue();
            expect($criteria->evaluate(4, 5))->toBeTrue();
        });

        it('returns false when succeeded is below minimum', function (): void {
            $criteria = NOfMCriteria::atLeast(3);
            expect($criteria->evaluate(2, 5))->toBeFalse();
        });

        it('adjusts minimum when total is less than required', function (): void {
            $criteria = NOfMCriteria::atLeast(10);
            expect($criteria->evaluate(5, 5))->toBeTrue();
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal criteria', function (): void {
            $a = NOfMCriteria::atLeast(3);
            $b = NOfMCriteria::atLeast(3);
            expect($a->equals($b))->toBeTrue();
        });

        it('returns false for different criteria', function (): void {
            $a = NOfMCriteria::atLeast(3);
            $b = NOfMCriteria::atLeast(5);
            expect($a->equals($b))->toBeFalse();
        });
    });
});
