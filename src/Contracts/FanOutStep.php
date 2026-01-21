<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Closure;
use Maestro\Workflow\Definition\Config\NOfMCriteria;
use Maestro\Workflow\Enums\SuccessCriteria;

/**
 * A step that dispatches multiple jobs in parallel (fan-out).
 */
interface FanOutStep extends StepDefinition
{
    /**
     * The fully qualified class name of the job to dispatch for each item.
     *
     * @return class-string
     */
    public function jobClass(): string;

    /**
     * Factory closure that creates the item iterator from workflow context and outputs.
     * Signature: function(WorkflowContext, StepOutputStore): iterable
     */
    public function itemIteratorFactory(): Closure;

    /**
     * Optional factory closure that modifies job construction.
     * Signature: function(mixed $item, WorkflowContext, StepOutputStore): array
     * Returns constructor arguments for the job.
     */
    public function jobArgumentsFactory(): ?Closure;

    /**
     * Maximum number of jobs to dispatch concurrently.
     * Null means unlimited.
     */
    public function parallelismLimit(): ?int;

    /**
     * Success criteria for determining step completion.
     */
    public function successCriteria(): SuccessCriteria|NOfMCriteria;
}
