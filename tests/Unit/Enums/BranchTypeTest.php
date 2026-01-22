<?php

declare(strict_types=1);

use Maestro\Workflow\Enums\BranchType;

describe('BranchType', static function (): void {
    describe('displayName', static function (): void {
        it('returns correct display name for Exclusive', function (): void {
            expect(BranchType::Exclusive->displayName())->toBe('Exclusive (XOR)');
        });

        it('returns correct display name for Inclusive', function (): void {
            expect(BranchType::Inclusive->displayName())->toBe('Inclusive (OR)');
        });
    });

    describe('isExclusive', static function (): void {
        it('returns true for Exclusive', function (): void {
            expect(BranchType::Exclusive->isExclusive())->toBeTrue();
        });

        it('returns false for Inclusive', function (): void {
            expect(BranchType::Inclusive->isExclusive())->toBeFalse();
        });
    });

    describe('isInclusive', static function (): void {
        it('returns true for Inclusive', function (): void {
            expect(BranchType::Inclusive->isInclusive())->toBeTrue();
        });

        it('returns false for Exclusive', function (): void {
            expect(BranchType::Exclusive->isInclusive())->toBeFalse();
        });
    });

    describe('backing values', static function (): void {
        it('has correct backing values', function (): void {
            expect(BranchType::Exclusive->value)->toBe('exclusive');
            expect(BranchType::Inclusive->value)->toBe('inclusive');
        });
    });
});
