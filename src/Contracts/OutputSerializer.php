<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Exceptions\SerializationException;

interface OutputSerializer
{
    public function serialize(StepOutput $output): string;

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     *
     * @throws SerializationException
     */
    public function deserialize(string $payload, string $outputClass): StepOutput;
}
