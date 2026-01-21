<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Serialization;

use Maestro\Workflow\Contracts\OutputSerializer;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Exceptions\SerializationException;

final readonly class PhpOutputSerializer implements OutputSerializer
{
    public function serialize(StepOutput $output): string
    {
        return serialize($output);
    }

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     *
     * @throws SerializationException
     */
    public function deserialize(string $payload, string $outputClass): StepOutput
    {
        $output = @unserialize($payload);

        if ($output === false) {
            throw SerializationException::deserializationFailed($outputClass, 'unserialize returned false');
        }

        if (! $output instanceof StepOutput) {
            throw SerializationException::deserializationFailed(
                $outputClass,
                'deserialized value does not implement StepOutput',
            );
        }

        if (! $output instanceof $outputClass) {
            throw SerializationException::typeMismatch($output::class, $outputClass);
        }

        /** @var T $output */
        return $output;
    }
}
