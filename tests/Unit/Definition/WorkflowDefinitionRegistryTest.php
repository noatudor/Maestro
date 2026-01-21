<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\DuplicateDefinitionException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('WorkflowDefinitionRegistry', static function (): void {
    beforeEach(function (): void {
        $this->registry = new WorkflowDefinitionRegistry();

        $this->definition1 = WorkflowDefinition::create(
            definitionKey: DefinitionKey::fromString('order-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
            displayName: 'Order Workflow v1',
            stepCollection: StepCollection::empty(),
        );

        $this->definition2 = WorkflowDefinition::create(
            definitionKey: DefinitionKey::fromString('order-workflow'),
            definitionVersion: DefinitionVersion::fromString('2.0.0'),
            displayName: 'Order Workflow v2',
            stepCollection: StepCollection::empty(),
        );

        $this->definition3 = WorkflowDefinition::create(
            definitionKey: DefinitionKey::fromString('payment-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
            displayName: 'Payment Workflow',
            stepCollection: StepCollection::empty(),
        );
    });

    describe('register', static function (): void {
        it('registers a workflow definition', function (): void {
            $this->registry->register($this->definition1);

            expect($this->registry->count())->toBe(1);
            expect($this->registry->has($this->definition1->key(), $this->definition1->version()))->toBeTrue();
        });

        it('throws when registering duplicate', function (): void {
            $this->registry->register($this->definition1);

            expect(fn () => $this->registry->register($this->definition1))
                ->toThrow(DuplicateDefinitionException::class);
        });

        it('allows different versions of same key', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);

            expect($this->registry->count())->toBe(2);
        });
    });

    describe('get', static function (): void {
        it('returns definition by key and version', function (): void {
            $this->registry->register($this->definition1);

            $result = $this->registry->get($this->definition1->key(), $this->definition1->version());

            expect($result)->toBe($this->definition1);
        });

        it('throws when not found', function (): void {
            expect(fn () => $this->registry->get(
                DefinitionKey::fromString('non-existent'),
                DefinitionVersion::initial(),
            ))->toThrow(DefinitionNotFoundException::class);
        });
    });

    describe('find', static function (): void {
        it('returns definition if found', function (): void {
            $this->registry->register($this->definition1);

            $result = $this->registry->find($this->definition1->key(), $this->definition1->version());

            expect($result)->toBe($this->definition1);
        });

        it('returns null if not found', function (): void {
            $result = $this->registry->find(
                DefinitionKey::fromString('non-existent'),
                DefinitionVersion::initial(),
            );

            expect($result)->toBeNull();
        });
    });

    describe('getLatest', static function (): void {
        it('returns latest version for key', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);

            $result = $this->registry->getLatest($this->definition1->key());

            expect($result)->toBe($this->definition2);
        });

        it('throws when key not found', function (): void {
            expect(fn () => $this->registry->getLatest(DefinitionKey::fromString('non-existent')))
                ->toThrow(DefinitionNotFoundException::class);
        });
    });

    describe('findLatest', static function (): void {
        it('returns latest version if found', function (): void {
            $this->registry->register($this->definition1);

            $result = $this->registry->findLatest($this->definition1->key());

            expect($result)->toBe($this->definition1);
        });

        it('returns null if key not found', function (): void {
            $result = $this->registry->findLatest(DefinitionKey::fromString('non-existent'));

            expect($result)->toBeNull();
        });
    });

    describe('has', static function (): void {
        it('returns true when definition exists', function (): void {
            $this->registry->register($this->definition1);

            expect($this->registry->has($this->definition1->key(), $this->definition1->version()))->toBeTrue();
        });

        it('returns false when definition does not exist', function (): void {
            expect($this->registry->has(
                DefinitionKey::fromString('non-existent'),
                DefinitionVersion::initial(),
            ))->toBeFalse();
        });

        it('checks key existence when version is null', function (): void {
            $this->registry->register($this->definition1);

            expect($this->registry->has($this->definition1->key()))->toBeTrue();
            expect($this->registry->has(DefinitionKey::fromString('non-existent')))->toBeFalse();
        });
    });

    describe('getAllVersions', static function (): void {
        it('returns all versions for a key', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);
            $this->registry->register($this->definition3);

            $versions = $this->registry->getAllVersions($this->definition1->key());

            expect($versions)->toHaveCount(2);
        });

        it('returns empty array for non-existent key', function (): void {
            $versions = $this->registry->getAllVersions(DefinitionKey::fromString('non-existent'));

            expect($versions)->toBe([]);
        });
    });

    describe('allKeys', static function (): void {
        it('returns all unique keys', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);
            $this->registry->register($this->definition3);

            $keys = $this->registry->allKeys();

            expect($keys)->toHaveCount(2);
        });
    });

    describe('all', static function (): void {
        it('returns all definitions', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);
            $this->registry->register($this->definition3);

            $all = $this->registry->all();

            expect($all)->toHaveCount(3);
        });
    });

    describe('clear', static function (): void {
        it('removes all definitions', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);

            $this->registry->clear();

            expect($this->registry->isEmpty())->toBeTrue();
            expect($this->registry->count())->toBe(0);
        });
    });

    describe('hasKey', static function (): void {
        it('returns true when key exists', function (): void {
            $this->registry->register($this->definition1);

            expect($this->registry->hasKey($this->definition1->key()))->toBeTrue();
        });

        it('returns false when key does not exist', function (): void {
            expect($this->registry->hasKey(DefinitionKey::fromString('non-existent')))->toBeFalse();
        });
    });

    describe('allLatest', static function (): void {
        it('returns latest definition for each key', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);
            $this->registry->register($this->definition3);

            $latest = $this->registry->allLatest();

            expect($latest)->toHaveCount(2);
        });
    });

    describe('countKeys', static function (): void {
        it('returns count of unique keys', function (): void {
            $this->registry->register($this->definition1);
            $this->registry->register($this->definition2);
            $this->registry->register($this->definition3);

            expect($this->registry->countKeys())->toBe(2);
        });

        it('returns zero for empty registry', function (): void {
            expect($this->registry->countKeys())->toBe(0);
        });
    });
});
