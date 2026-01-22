<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Exceptions\MissingRequiredOutputException;

/**
 * Read-only access to step outputs for condition evaluation.
 */
interface StepOutputReader
{
    /**
     * Read a step output by its class type.
     *
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     *
     * @throws MissingRequiredOutputException
     */
    public function read(string $outputClass): StepOutput;

    /**
     * Check if an output exists.
     *
     * @param class-string<StepOutput> $outputClass
     */
    public function has(string $outputClass): bool;
}
