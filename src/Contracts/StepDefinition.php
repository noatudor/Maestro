<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Definition\Config\CompensationDefinition;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
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

    /**
     * Whether this step has a compensation defined.
     */
    public function hasCompensation(): bool;

    /**
     * Get the compensation definition for this step.
     */
    public function compensation(): ?CompensationDefinition;

    /**
     * Get the step condition class, if any.
     *
     * @return class-string<StepCondition>|null
     */
    public function conditionClass(): ?string;

    /**
     * Whether this step has a condition that must be evaluated.
     */
    public function hasCondition(): bool;

    /**
     * Get the termination condition class, if any.
     *
     * @return class-string<TerminationCondition>|null
     */
    public function terminationConditionClass(): ?string;

    /**
     * Whether this step has a termination condition.
     */
    public function hasTerminationCondition(): bool;

    /**
     * Get the branch definition if this step is a branch point.
     */
    public function branchDefinition(): ?BranchDefinition;

    /**
     * Whether this step is a branch point.
     */
    public function isBranchPoint(): bool;

    /**
     * Get the pause trigger definition if this step pauses after completion.
     */
    public function pauseTrigger(): ?PauseTriggerDefinition;

    /**
     * Whether this step has a pause trigger configured.
     */
    public function hasPauseTrigger(): bool;
}
