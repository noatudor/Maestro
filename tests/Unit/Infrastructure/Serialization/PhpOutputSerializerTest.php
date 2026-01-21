<?php

declare(strict_types=1);

use Maestro\Workflow\Exceptions\SerializationException;
use Maestro\Workflow\Infrastructure\Serialization\PhpOutputSerializer;
use Maestro\Workflow\Tests\Fixtures\Outputs\AnotherOutput;
use Maestro\Workflow\Tests\Fixtures\Outputs\TestOutput;

describe('PhpOutputSerializer', function (): void {
    beforeEach(function (): void {
        $this->serializer = new PhpOutputSerializer();
    });

    describe('serialize', function (): void {
        it('serializes a step output', function (): void {
            $output = new TestOutput('test-value');

            $serialized = $this->serializer->serialize($output);

            expect($serialized)->toBeString();
            expect($serialized)->not->toBeEmpty();
        });

        it('serializes different output types', function (): void {
            $testOutput = new TestOutput('value');
            $anotherOutput = new AnotherOutput(42);

            $serializedTest = $this->serializer->serialize($testOutput);
            $serializedAnother = $this->serializer->serialize($anotherOutput);

            expect($serializedTest)->not->toBe($serializedAnother);
        });
    });

    describe('deserialize', function (): void {
        it('deserializes a step output back to its original type', function (): void {
            $original = new TestOutput('test-value');
            $serialized = $this->serializer->serialize($original);

            $deserialized = $this->serializer->deserialize($serialized, TestOutput::class);

            expect($deserialized)->toBeInstanceOf(TestOutput::class);
            expect($deserialized->value)->toBe('test-value');
        });

        it('preserves all properties', function (): void {
            $original = new AnotherOutput(42);
            $serialized = $this->serializer->serialize($original);

            $deserialized = $this->serializer->deserialize($serialized, AnotherOutput::class);

            expect($deserialized)->toBeInstanceOf(AnotherOutput::class);
            expect($deserialized->count)->toBe(42);
        });

        it('throws on type mismatch', function (): void {
            $original = new TestOutput('value');
            $serialized = $this->serializer->serialize($original);

            $this->serializer->deserialize($serialized, AnotherOutput::class);
        })->throws(SerializationException::class);

        it('throws on invalid payload', function (): void {
            $this->serializer->deserialize('invalid-payload', TestOutput::class);
        })->throws(SerializationException::class);
    });

    describe('roundtrip', function (): void {
        it('maintains data integrity through serialization roundtrip', function (): void {
            $original = new TestOutput('roundtrip-test');

            $serialized = $this->serializer->serialize($original);
            $deserialized = $this->serializer->deserialize($serialized, TestOutput::class);

            expect($deserialized->value)->toBe($original->value);
            expect($deserialized->stepKey()->equals($original->stepKey()))->toBeTrue();
        });
    });
});
