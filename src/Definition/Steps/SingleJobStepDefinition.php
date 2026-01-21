<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Steps;

use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class SingleJobStepDefinition extends AbstractStepDefinition implements SingleJobStep
{
    /**
     * @param class-string $jobClass
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     */
    private function __construct(
        StepKey $stepKey,
        string $displayName,
        private string $jobClass,
        array $requires,
        ?string $produces,
        FailurePolicy $failurePolicy,
        RetryConfiguration $retryConfiguration,
        StepTimeout $stepTimeout,
        QueueConfiguration $queueConfiguration,
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
        );
    }

    /**
     * @param class-string $jobClass
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     */
    public static function create(
        StepKey $stepKey,
        string $displayName,
        string $jobClass,
        array $requires = [],
        ?string $produces = null,
        FailurePolicy $failurePolicy = FailurePolicy::FailWorkflow,
        ?RetryConfiguration $retryConfiguration = null,
        ?StepTimeout $stepTimeout = null,
        ?QueueConfiguration $queueConfiguration = null,
    ): self {
        return new self(
            $stepKey,
            $displayName,
            $jobClass,
            $requires,
            $produces,
            $failurePolicy,
            $retryConfiguration ?? RetryConfiguration::default(),
            $stepTimeout ?? StepTimeout::none(),
            $queueConfiguration ?? QueueConfiguration::default(),
        );
    }

    public function jobClass(): string
    {
        return $this->jobClass;
    }
}
