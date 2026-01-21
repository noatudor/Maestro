<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

/**
 * A step that dispatches exactly one job.
 */
interface SingleJobStep extends StepDefinition
{
    /**
     * The fully qualified class name of the job to dispatch.
     *
     * @return class-string
     */
    public function jobClass(): string;
}
