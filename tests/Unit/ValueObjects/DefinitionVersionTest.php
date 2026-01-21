<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\InvalidDefinitionVersionException;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('DefinitionVersion', static function (): void {
    describe('creation', static function (): void {
        it('creates from valid semver string', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.3');

            expect($definitionVersion->major)->toBe(1);
            expect($definitionVersion->minor)->toBe(2);
            expect($definitionVersion->patch)->toBe(3);
        });

        it('creates from components', function (): void {
            $definitionVersion = DefinitionVersion::create(2, 1, 0);

            expect($definitionVersion->major)->toBe(2);
            expect($definitionVersion->minor)->toBe(1);
            expect($definitionVersion->patch)->toBe(0);
        });

        it('creates initial version', function (): void {
            $definitionVersion = DefinitionVersion::initial();

            expect($definitionVersion->major)->toBe(1);
            expect($definitionVersion->minor)->toBe(0);
            expect($definitionVersion->patch)->toBe(0);
        });

        it('throws on empty string', function (): void {
            expect(static fn (): DefinitionVersion => DefinitionVersion::fromString(''))
                ->toThrow(InvalidDefinitionVersionException::class, 'cannot be empty');
        });

        it('throws on whitespace only', function (): void {
            expect(static fn (): DefinitionVersion => DefinitionVersion::fromString('   '))
                ->toThrow(InvalidDefinitionVersionException::class, 'cannot be empty');
        });

        it('throws on invalid format', function (string $version): void {
            expect(static fn (): DefinitionVersion => DefinitionVersion::fromString($version))
                ->toThrow(InvalidDefinitionVersionException::class, 'invalid format');
        })->with([
            'missing patch' => '1.2',
            'missing minor' => '1',
            'with v prefix' => 'v1.2.3',
            'with prerelease' => '1.2.3-beta',
            'non-numeric' => 'a.b.c',
            'negative numbers' => '-1.2.3',
        ]);
    });

    describe('comparison', static function (): void {
        it('compares equality correctly', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.3');
            $same = DefinitionVersion::fromString('1.2.3');
            $different = DefinitionVersion::fromString('1.2.4');

            expect($definitionVersion->equals($same))->toBeTrue();
            expect($definitionVersion->equals($different))->toBeFalse();
        });

        it('determines if version is newer by major', function (): void {
            $definitionVersion = DefinitionVersion::fromString('2.0.0');
            $v2 = DefinitionVersion::fromString('1.9.9');

            expect($definitionVersion->isNewerThan($v2))->toBeTrue();
            expect($v2->isNewerThan($definitionVersion))->toBeFalse();
        });

        it('determines if version is newer by minor', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.3.0');
            $v2 = DefinitionVersion::fromString('1.2.9');

            expect($definitionVersion->isNewerThan($v2))->toBeTrue();
            expect($v2->isNewerThan($definitionVersion))->toBeFalse();
        });

        it('determines if version is newer by patch', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.4');
            $v2 = DefinitionVersion::fromString('1.2.3');

            expect($definitionVersion->isNewerThan($v2))->toBeTrue();
            expect($v2->isNewerThan($definitionVersion))->toBeFalse();
        });

        it('returns false when comparing equal versions for newer', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.3');
            $v2 = DefinitionVersion::fromString('1.2.3');

            expect($definitionVersion->isNewerThan($v2))->toBeFalse();
        });
    });

    describe('compatibility', static function (): void {
        it('considers same major version as compatible', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.3');
            $v2 = DefinitionVersion::fromString('1.5.0');

            expect($definitionVersion->isCompatibleWith($v2))->toBeTrue();
        });

        it('considers different major version as incompatible', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.3');
            $v2 = DefinitionVersion::fromString('2.0.0');

            expect($definitionVersion->isCompatibleWith($v2))->toBeFalse();
        });
    });

    describe('string conversion', static function (): void {
        it('converts to string', function (): void {
            $definitionVersion = DefinitionVersion::fromString('1.2.3');

            expect($definitionVersion->toString())->toBe('1.2.3');
            expect((string) $definitionVersion)->toBe('1.2.3');
        });
    });

    it('is readonly', function (): void {
        expect(DefinitionVersion::class)->toBeImmutable();
    });
});
