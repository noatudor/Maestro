<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\CompensationRunId;

describe('CompensationRunId', function () {
    it('generates a new id', function () {
        $id = CompensationRunId::generate();

        expect($id)->toBeInstanceOf(CompensationRunId::class)
            ->and($id->value)->toBeValidUuid();
    });

    it('creates from string', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id = CompensationRunId::fromString($value);

        expect($id->value)->toBe($value);
    });

    it('compares equality correctly', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id1 = CompensationRunId::fromString($value);
        $id2 = CompensationRunId::fromString($value);
        $id3 = CompensationRunId::generate();

        expect($id1->equals($id2))->toBeTrue()
            ->and($id1->equals($id3))->toBeFalse();
    });

    it('converts to string', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id = CompensationRunId::fromString($value);

        expect($id->toString())->toBe($value);
    });
});
