<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\StepRunId;

describe('StepRunId', static function (): void {
    it('generates a valid UUIDv7', function (): void {
        $stepRunId = StepRunId::generate();

        expect($stepRunId->value)->toBeValidUuid();
    });

    it('creates from string', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $stepRunId = StepRunId::fromString($value);

        expect($stepRunId->value)->toBe($value);
    });

    it('compares equality correctly', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $stepRunId = StepRunId::fromString($value);
        $id2 = StepRunId::fromString($value);
        $id3 = StepRunId::generate();

        expect($stepRunId->equals($id2))->toBeTrue();
        expect($stepRunId->equals($id3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $stepRunId = StepRunId::fromString($value);

        expect($stepRunId->toString())->toBe($value);
        expect((string) $stepRunId)->toBe($value);
    });

    it('is readonly', function (): void {
        expect(StepRunId::class)->toBeImmutable();
    });
});
