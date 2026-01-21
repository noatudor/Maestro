<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Steps;

use Closure;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Definition\Config\NOfMCriteria;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\SuccessCriteria;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class FanOutStepDefinition extends AbstractStepDefinition implements FanOutStep
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
        private Closure $itemIteratorFactory,
        private ?Closure $jobArgumentsFactory,
        private ?int $parallelismLimit,
        private SuccessCriteria|NOfMCriteria $successCriteria,
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
        Closure $itemIteratorFactory,
        ?Closure $jobArgumentsFactory = null,
        ?int $parallelismLimit = null,
        SuccessCriteria|NOfMCriteria $successCriteria = SuccessCriteria::All,
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
            $itemIteratorFactory,
            $jobArgumentsFactory,
            $parallelismLimit !== null ? max(1, $parallelismLimit) : null,
            $successCriteria,
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

    public function itemIteratorFactory(): Closure
    {
        return $this->itemIteratorFactory;
    }

    public function jobArgumentsFactory(): ?Closure
    {
        return $this->jobArgumentsFactory;
    }

    public function parallelismLimit(): ?int
    {
        return $this->parallelismLimit;
    }

    public function successCriteria(): SuccessCriteria|NOfMCriteria
    {
        return $this->successCriteria;
    }

    public function hasParallelismLimit(): bool
    {
        return $this->parallelismLimit !== null;
    }

    public function evaluateSuccess(int $succeeded, int $total): bool
    {
        if ($this->successCriteria instanceof NOfMCriteria) {
            return $this->successCriteria->evaluate($succeeded, $total);
        }

        return $this->successCriteria->evaluate($succeeded, $total);
    }
}
