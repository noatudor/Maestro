<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\MaestroException;
use Maestro\Workflow\Exceptions\SerializationException;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;

describe('SerializationException', static function (): void {
    it('extends MaestroException', function (): void {
        $serializationException = SerializationException::serializationFailed('test reason');

        expect($serializationException)->toBeInstanceOf(MaestroException::class);
    });

    describe('deserializationFailed', static function (): void {
        it('creates exception with correct message', function (): void {
            $serializationException = SerializationException::deserializationFailed(
                TestOutput::class,
                'unserialize returned false',
            );

            expect($serializationException->getMessage())
                ->toContain(TestOutput::class)
                ->toContain('unserialize returned false');
        });
    });

    describe('typeMismatch', static function (): void {
        it('creates exception with correct message', function (): void {
            $serializationException = SerializationException::typeMismatch(
                'stdClass',
                TestOutput::class,
            );

            expect($serializationException->getMessage())
                ->toContain('stdClass')
                ->toContain(TestOutput::class);
        });
    });

    describe('serializationFailed', static function (): void {
        it('creates exception with correct message', function (): void {
            $serializationException = SerializationException::serializationFailed('serialize error');

            expect($serializationException->getMessage())
                ->toContain('Failed to serialize output')
                ->toContain('serialize error');
        });
    });
});
