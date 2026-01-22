<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\InvalidBranchKeyException;
use Maestro\Workflow\ValueObjects\BranchKey;

describe('BranchKey', static function (): void {
    it('creates from valid string', function (): void {
        $branchKey = BranchKey::fromString('success-branch');

        expect($branchKey->value)->toBe('success-branch');
    });

    it('accepts lowercase letters only', function (): void {
        $branchKey = BranchKey::fromString('branch');

        expect($branchKey->value)->toBe('branch');
    });

    it('accepts numbers after first character', function (): void {
        $branchKey = BranchKey::fromString('branch1');

        expect($branchKey->value)->toBe('branch1');
    });

    it('accepts hyphens', function (): void {
        $branchKey = BranchKey::fromString('my-branch-name');

        expect($branchKey->value)->toBe('my-branch-name');
    });

    it('throws on empty string', function (): void {
        expect(static fn (): BranchKey => BranchKey::fromString(''))
            ->toThrow(InvalidBranchKeyException::class, 'cannot be empty');
    });

    it('throws on whitespace only', function (): void {
        expect(static fn (): BranchKey => BranchKey::fromString('   '))
            ->toThrow(InvalidBranchKeyException::class, 'cannot be empty');
    });

    it('throws on uppercase letters', function (): void {
        expect(static fn (): BranchKey => BranchKey::fromString('SuccessBranch'))
            ->toThrow(InvalidBranchKeyException::class, 'invalid format');
    });

    it('throws on starting with number', function (): void {
        expect(static fn (): BranchKey => BranchKey::fromString('1branch'))
            ->toThrow(InvalidBranchKeyException::class, 'invalid format');
    });

    it('throws on special characters', function (): void {
        expect(static fn (): BranchKey => BranchKey::fromString('branch_name'))
            ->toThrow(InvalidBranchKeyException::class, 'invalid format');
    });

    it('compares equality correctly', function (): void {
        $branchKey1 = BranchKey::fromString('success-branch');
        $branchKey2 = BranchKey::fromString('success-branch');
        $branchKey3 = BranchKey::fromString('failure-branch');

        expect($branchKey1->equals($branchKey2))->toBeTrue();
        expect($branchKey1->equals($branchKey3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $branchKey = BranchKey::fromString('success-branch');

        expect($branchKey->toString())->toBe('success-branch');
        expect((string) $branchKey)->toBe('success-branch');
    });

    it('is readonly', function (): void {
        expect(BranchKey::class)->toBeImmutable();
    });
});
