<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\MaestroException;
use Maestro\Workflow\Exceptions\SerializationException;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;

describe('SerializationException', function (): void {
    it('extends MaestroException', function (): void {
        $exception = SerializationException::serializationFailed('test reason');

        expect($exception)->toBeInstanceOf(MaestroException::class);
    });

    describe('deserializationFailed', function (): void {
        it('creates exception with correct message', function (): void {
            $exception = SerializationException::deserializationFailed(
                TestOutput::class,
                'unserialize returned false',
            );

            expect($exception->getMessage())
                ->toContain(TestOutput::class)
                ->toContain('unserialize returned false');
        });
    });

    describe('typeMismatch', function (): void {
        it('creates exception with correct message', function (): void {
            $exception = SerializationException::typeMismatch(
                'stdClass',
                TestOutput::class,
            );

            expect($exception->getMessage())
                ->toContain('stdClass')
                ->toContain(TestOutput::class);
        });
    });

    describe('serializationFailed', function (): void {
        it('creates exception with correct message', function (): void {
            $exception = SerializationException::serializationFailed('serialize error');

            expect($exception->getMessage())
                ->toContain('Failed to serialize output')
                ->toContain('serialize error');
        });
    });
});
