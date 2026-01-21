<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\ValueObjects\DefinitionKey;

describe('DefinitionKey', static function (): void {
    it('creates from valid string', function (): void {
        $definitionKey = DefinitionKey::fromString('order-fulfillment');

        expect($definitionKey->value)->toBe('order-fulfillment');
    });

    it('accepts lowercase letters only', function (): void {
        $definitionKey = DefinitionKey::fromString('workflow');

        expect($definitionKey->value)->toBe('workflow');
    });

    it('accepts numbers after first character', function (): void {
        $definitionKey = DefinitionKey::fromString('workflow1');

        expect($definitionKey->value)->toBe('workflow1');
    });

    it('accepts hyphens', function (): void {
        $definitionKey = DefinitionKey::fromString('my-workflow-name');

        expect($definitionKey->value)->toBe('my-workflow-name');
    });

    it('throws on empty string', function (): void {
        expect(static fn (): DefinitionKey => DefinitionKey::fromString(''))
            ->toThrow(InvalidDefinitionKeyException::class, 'cannot be empty');
    });

    it('throws on whitespace only', function (): void {
        expect(static fn (): DefinitionKey => DefinitionKey::fromString('   '))
            ->toThrow(InvalidDefinitionKeyException::class, 'cannot be empty');
    });

    it('throws on uppercase letters', function (): void {
        expect(static fn (): DefinitionKey => DefinitionKey::fromString('OrderFulfillment'))
            ->toThrow(InvalidDefinitionKeyException::class, 'invalid format');
    });

    it('throws on starting with number', function (): void {
        expect(static fn (): DefinitionKey => DefinitionKey::fromString('1workflow'))
            ->toThrow(InvalidDefinitionKeyException::class, 'invalid format');
    });

    it('throws on special characters', function (): void {
        expect(static fn (): DefinitionKey => DefinitionKey::fromString('workflow_name'))
            ->toThrow(InvalidDefinitionKeyException::class, 'invalid format');
    });

    it('compares equality correctly', function (): void {
        $definitionKey = DefinitionKey::fromString('order-fulfillment');
        $key2 = DefinitionKey::fromString('order-fulfillment');
        $key3 = DefinitionKey::fromString('payment-processing');

        expect($definitionKey->equals($key2))->toBeTrue();
        expect($definitionKey->equals($key3))->toBeFalse();
    });

    it('converts to string', function (): void {
        $definitionKey = DefinitionKey::fromString('order-fulfillment');

        expect($definitionKey->toString())->toBe('order-fulfillment');
        expect((string) $definitionKey)->toBe('order-fulfillment');
    });

    it('is readonly', function (): void {
        expect(DefinitionKey::class)->toBeImmutable();
    });
});
