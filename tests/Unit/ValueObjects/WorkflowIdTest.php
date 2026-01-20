<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowId', function () {
    it('generates a valid UUIDv7', function () {
        $id = WorkflowId::generate();

        expect($id->value)->toBeValidUuid();
    });

    it('creates from string', function () {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $id = WorkflowId::fromString($value);

        expect($id->value)->toBe($value);
    });

    it('compares equality correctly', function () {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $id1 = WorkflowId::fromString($value);
        $id2 = WorkflowId::fromString($value);
        $id3 = WorkflowId::generate();

        expect($id1->equals($id2))->toBeTrue();
        expect($id1->equals($id3))->toBeFalse();
    });

    it('converts to string', function () {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $id = WorkflowId::fromString($value);

        expect($id->toString())->toBe($value);
        expect((string) $id)->toBe($value);
    });

    it('is readonly', function () {
        expect(WorkflowId::class)->toBeImmutable();
    });
});
