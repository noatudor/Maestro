<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\ValueObjects\StepKey;

/**
 * Defines a step within a workflow.
 */
interface StepDefinition
{
    /**
     * The unique key identifying this step within the workflow.
     */
    public function key(): StepKey;

    /**
     * Human-readable display name for the step.
     */
    public function displayName(): string;

    /**
     * Output classes required from prior steps.
     *
     * @return list<class-string<StepOutput>>
     */
    public function requires(): array;

    /**
     * The output class this step produces, if any.
     *
     * @return class-string<StepOutput>|null
     */
    public function produces(): ?string;

    /**
     * The failure policy for this step.
     */
    public function failurePolicy(): FailurePolicy;

    /**
     * Retry configuration for this step.
     */
    public function retryConfiguration(): RetryConfiguration;

    /**
     * Timeout configuration for this step.
     */
    public function timeout(): StepTimeout;

    /**
     * Queue configuration for job dispatch.
     */
    public function queueConfiguration(): QueueConfiguration;
}
