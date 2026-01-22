<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Steps;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Definition\Config\CompensationDefinition;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\ValueObjects\StepKey;

abstract readonly class AbstractStepDefinition implements StepDefinition
{
    /**
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     * @param class-string<StepCondition>|null $conditionClass
     * @param class-string<TerminationCondition>|null $terminationConditionClass
     */
    protected function __construct(
        private StepKey $stepKey,
        private string $displayName,
        private array $requires,
        private ?string $produces,
        private FailurePolicy $failurePolicy,
        private RetryConfiguration $retryConfiguration,
        private StepTimeout $stepTimeout,
        private QueueConfiguration $queueConfiguration,
        private ?CompensationDefinition $compensationDefinition = null,
        private ?string $conditionClass = null,
        private ?string $terminationConditionClass = null,
        private ?BranchDefinition $branchDefinition = null,
        private ?PauseTriggerDefinition $pauseTriggerDefinition = null,
    ) {}

    final public function key(): StepKey
    {
        return $this->stepKey;
    }

    final public function displayName(): string
    {
        return $this->displayName;
    }

    final public function requires(): array
    {
        return $this->requires;
    }

    final public function produces(): ?string
    {
        return $this->produces;
    }

    final public function failurePolicy(): FailurePolicy
    {
        return $this->failurePolicy;
    }

    final public function retryConfiguration(): RetryConfiguration
    {
        return $this->retryConfiguration;
    }

    final public function timeout(): StepTimeout
    {
        return $this->stepTimeout;
    }

    final public function queueConfiguration(): QueueConfiguration
    {
        return $this->queueConfiguration;
    }

    final public function hasRequirements(): bool
    {
        return $this->requires !== [];
    }

    final public function producesOutput(): bool
    {
        return $this->produces !== null;
    }

    final public function compensation(): ?CompensationDefinition
    {
        return $this->compensationDefinition;
    }

    final public function hasCompensation(): bool
    {
        return $this->compensationDefinition instanceof CompensationDefinition;
    }

    final public function conditionClass(): ?string
    {
        return $this->conditionClass;
    }

    final public function hasCondition(): bool
    {
        return $this->conditionClass !== null;
    }

    final public function terminationConditionClass(): ?string
    {
        return $this->terminationConditionClass;
    }

    final public function hasTerminationCondition(): bool
    {
        return $this->terminationConditionClass !== null;
    }

    final public function branchDefinition(): ?BranchDefinition
    {
        return $this->branchDefinition;
    }

    final public function isBranchPoint(): bool
    {
        return $this->branchDefinition instanceof BranchDefinition;
    }

    final public function pauseTrigger(): ?PauseTriggerDefinition
    {
        return $this->pauseTriggerDefinition;
    }

    final public function hasPauseTrigger(): bool
    {
        return $this->pauseTriggerDefinition instanceof PauseTriggerDefinition;
    }
}
