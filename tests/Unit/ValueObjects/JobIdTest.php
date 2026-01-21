<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\JobId;

describe('JobId', static function (): void {
    it('generates a valid UUIDv7', function (): void {
        $jobId = JobId::generate();

        expect($jobId->value)->toBeValidUuid();
    });

    it('creates from string', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $jobId = JobId::fromString($value);

        expect($jobId->value)->toBe($value);
    });

    it('compares equality correctly', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $jobId = JobId::fromString($value);
        $id2 = JobId::fromString($value);
        $id3 = JobId::generate();

        expect($jobId->equals($id2))->toBeTrue();
        expect($jobId->equals($id3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $jobId = JobId::fromString($value);

        expect($jobId->toString())->toBe($value);
        expect((string) $jobId)->toBe($value);
    });

    it('is readonly', function (): void {
        expect(JobId::class)->toBeImmutable();
    });
});
