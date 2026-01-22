<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\TriggerPayloadId;

describe('TriggerPayloadId', static function (): void {
    describe('generate()', static function (): void {
        it('generates a unique ID', function (): void {
            $triggerPayloadId = TriggerPayloadId::generate();

            expect($triggerPayloadId->value)->toBeString()
                ->and(strlen($triggerPayloadId->value))->toBe(36);
        });

        it('generates unique IDs each time', function (): void {
            $triggerPayloadId = TriggerPayloadId::generate();
            $id2 = TriggerPayloadId::generate();

            expect($triggerPayloadId->value)->not->toBe($id2->value);
        });
    });

    describe('fromString()', static function (): void {
        it('creates ID from string', function (): void {
            $triggerPayloadId = TriggerPayloadId::fromString('01234567-89ab-cdef-0123-456789abcdef');

            expect($triggerPayloadId->value)->toBe('01234567-89ab-cdef-0123-456789abcdef');
        });
    });

    describe('equals()', static function (): void {
        it('returns true for same value', function (): void {
            $triggerPayloadId = TriggerPayloadId::fromString('test-id');
            $id2 = TriggerPayloadId::fromString('test-id');

            expect($triggerPayloadId->equals($id2))->toBeTrue();
        });

        it('returns false for different values', function (): void {
            $triggerPayloadId = TriggerPayloadId::fromString('test-id-1');
            $id2 = TriggerPayloadId::fromString('test-id-2');

            expect($triggerPayloadId->equals($id2))->toBeFalse();
        });
    });

    describe('toString()', static function (): void {
        it('returns the string value', function (): void {
            $triggerPayloadId = TriggerPayloadId::fromString('test-id');

            expect($triggerPayloadId->toString())->toBe('test-id');
        });
    });
});
