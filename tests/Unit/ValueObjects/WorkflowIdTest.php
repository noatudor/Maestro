<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowId', static function (): void {
    it('generates a valid UUIDv7', function (): void {
        $workflowId = WorkflowId::generate();

        expect($workflowId->value)->toBeValidUuid();
    });

    it('creates from string', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $workflowId = WorkflowId::fromString($value);

        expect($workflowId->value)->toBe($value);
    });

    it('compares equality correctly', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $workflowId = WorkflowId::fromString($value);
        $id2 = WorkflowId::fromString($value);
        $id3 = WorkflowId::generate();

        expect($workflowId->equals($id2))->toBeTrue();
        expect($workflowId->equals($id3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $value = '01234567-89ab-7def-8123-456789abcdef';
        $workflowId = WorkflowId::fromString($value);

        expect($workflowId->toString())->toBe($value);
        expect((string) $workflowId)->toBe($value);
    });

    it('is readonly', function (): void {
        expect(WorkflowId::class)->toBeImmutable();
    });
});
