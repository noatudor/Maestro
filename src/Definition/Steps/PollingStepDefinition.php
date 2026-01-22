<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Steps;

use Maestro\Workflow\Contracts\PollingStep;
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Definition\Config\CompensationDefinition;
use Maestro\Workflow\Definition\Config\PollingConfiguration;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class PollingStepDefinition extends AbstractStepDefinition implements PollingStep
{
    /**
     * @param class-string $jobClass
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     * @param class-string<StepCondition>|null $conditionClass
     * @param class-string<TerminationCondition>|null $terminationConditionClass
     */
    private function __construct(
        StepKey $stepKey,
        string $displayName,
        private string $jobClass,
        private PollingConfiguration $pollingConfiguration,
        array $requires,
        ?string $produces,
        FailurePolicy $failurePolicy,
        RetryConfiguration $retryConfiguration,
        StepTimeout $stepTimeout,
        QueueConfiguration $queueConfiguration,
        ?CompensationDefinition $compensationDefinition,
        ?string $conditionClass,
        ?string $terminationConditionClass,
        ?BranchDefinition $branchDefinition,
    ) {
        parent::__construct(
            $stepKey,
            $displayName,
            $requires,
            $produces,
            $failurePolicy,
            $retryConfiguration,
            $stepTimeout,
            $queueConfiguration,
            $compensationDefinition,
            $conditionClass,
            $terminationConditionClass,
            $branchDefinition,
        );
    }

    /**
     * @param class-string $jobClass
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     * @param class-string<StepCondition>|null $conditionClass
     * @param class-string<TerminationCondition>|null $terminationConditionClass
     */
    public static function create(
        StepKey $stepKey,
        string $displayName,
        string $jobClass,
        ?PollingConfiguration $pollingConfiguration = null,
        array $requires = [],
        ?string $produces = null,
        FailurePolicy $failurePolicy = FailurePolicy::FailWorkflow,
        ?RetryConfiguration $retryConfiguration = null,
        ?StepTimeout $stepTimeout = null,
        ?QueueConfiguration $queueConfiguration = null,
        ?CompensationDefinition $compensationDefinition = null,
        ?string $conditionClass = null,
        ?string $terminationConditionClass = null,
        ?BranchDefinition $branchDefinition = null,
    ): self {
        return new self(
            $stepKey,
            $displayName,
            $jobClass,
            $pollingConfiguration ?? PollingConfiguration::default(),
            $requires,
            $produces,
            $failurePolicy,
            $retryConfiguration ?? RetryConfiguration::default(),
            $stepTimeout ?? StepTimeout::none(),
            $queueConfiguration ?? QueueConfiguration::default(),
            $compensationDefinition,
            $conditionClass,
            $terminationConditionClass,
            $branchDefinition,
        );
    }

    public function jobClass(): string
    {
        return $this->jobClass;
    }

    public function pollingConfiguration(): PollingConfiguration
    {
        return $this->pollingConfiguration;
    }
}
