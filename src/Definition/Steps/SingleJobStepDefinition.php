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
        StepKey $key,
        string $displayName,
        private string $jobClass,
        array $requires,
        ?string $produces,
        FailurePolicy $failurePolicy,
        RetryConfiguration $retryConfiguration,
        StepTimeout $timeout,
        QueueConfiguration $queueConfiguration,
    ) {
        parent::__construct(
            $key,
            $displayName,
            $requires,
            $produces,
            $failurePolicy,
            $retryConfiguration,
            $timeout,
            $queueConfiguration,
        );
    }

    /**
     * @param class-string $jobClass
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     */
    public static function create(
        StepKey $key,
        string $displayName,
        string $jobClass,
        array $requires = [],
        ?string $produces = null,
        FailurePolicy $failurePolicy = FailurePolicy::FailWorkflow,
        ?RetryConfiguration $retryConfiguration = null,
        ?StepTimeout $timeout = null,
        ?QueueConfiguration $queueConfiguration = null,
    ): self {
        return new self(
            $key,
            $displayName,
            $jobClass,
            $requires,
            $produces,
            $failurePolicy,
            $retryConfiguration ?? RetryConfiguration::default(),
            $timeout ?? StepTimeout::none(),
            $queueConfiguration ?? QueueConfiguration::default(),
        );
    }

    public function jobClass(): string
    {
        return $this->jobClass;
    }
}
