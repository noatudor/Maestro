<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\TriggerPayload;

describe('TriggerPayload', static function (): void {
    describe('fromArray', static function (): void {
        it('creates payload from array', function (): void {
            $data = ['key' => 'value', 'number' => 42];
            $triggerPayload = TriggerPayload::fromArray($data);

            expect($triggerPayload->toArray())->toBe($data);
        });

        it('creates empty payload from empty array', function (): void {
            $triggerPayload = TriggerPayload::fromArray([]);

            expect($triggerPayload->isEmpty())->toBeTrue();
            expect($triggerPayload->toArray())->toBe([]);
        });
    });

    describe('empty', static function (): void {
        it('creates empty payload', function (): void {
            $triggerPayload = TriggerPayload::empty();

            expect($triggerPayload->isEmpty())->toBeTrue();
            expect($triggerPayload->toArray())->toBe([]);
        });
    });

    describe('has', static function (): void {
        it('returns true for existing key', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->has('key'))->toBeTrue();
        });

        it('returns false for missing key', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->has('other'))->toBeFalse();
        });

        it('returns true for null value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => null]);

            expect($triggerPayload->has('key'))->toBeTrue();
        });
    });

    describe('get', static function (): void {
        it('returns value for existing key', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->get('key'))->toBe('value');
        });

        it('returns default for missing key', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->get('other', 'default'))->toBe('default');
        });

        it('returns null as default for missing key', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->get('other'))->toBeNull();
        });
    });

    describe('getString', static function (): void {
        it('returns string value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->getString('key'))->toBe('value');
        });

        it('returns default for non-string value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 42]);

            expect($triggerPayload->getString('key', 'default'))->toBe('default');
        });

        it('returns empty string as default', function (): void {
            $triggerPayload = TriggerPayload::fromArray([]);

            expect($triggerPayload->getString('key'))->toBe('');
        });
    });

    describe('getInt', static function (): void {
        it('returns int value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 42]);

            expect($triggerPayload->getInt('key'))->toBe(42);
        });

        it('converts numeric string to int', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => '123']);

            expect($triggerPayload->getInt('key'))->toBe(123);
        });

        it('returns default for non-numeric value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'text']);

            expect($triggerPayload->getInt('key', 99))->toBe(99);
        });

        it('returns zero as default', function (): void {
            $triggerPayload = TriggerPayload::fromArray([]);

            expect($triggerPayload->getInt('key'))->toBe(0);
        });
    });

    describe('getBool', static function (): void {
        it('returns true value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => true]);

            expect($triggerPayload->getBool('key'))->toBeTrue();
        });

        it('returns false value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => false]);

            expect($triggerPayload->getBool('key'))->toBeFalse();
        });

        it('returns default for non-boolean value', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'yes']);

            expect($triggerPayload->getBool('key', true))->toBeTrue();
        });

        it('returns false as default', function (): void {
            $triggerPayload = TriggerPayload::fromArray([]);

            expect($triggerPayload->getBool('key'))->toBeFalse();
        });
    });

    describe('isEmpty', static function (): void {
        it('returns true for empty payload', function (): void {
            $triggerPayload = TriggerPayload::fromArray([]);

            expect($triggerPayload->isEmpty())->toBeTrue();
        });

        it('returns false for non-empty payload', function (): void {
            $triggerPayload = TriggerPayload::fromArray(['key' => 'value']);

            expect($triggerPayload->isEmpty())->toBeFalse();
        });
    });

    describe('toArray', static function (): void {
        it('returns original data', function (): void {
            $data = ['a' => 1, 'b' => ['nested' => true]];
            $triggerPayload = TriggerPayload::fromArray($data);

            expect($triggerPayload->toArray())->toBe($data);
        });
    });
});
