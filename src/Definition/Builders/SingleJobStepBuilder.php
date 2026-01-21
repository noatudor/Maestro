<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Builders;

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Definition\Steps\SingleJobStepDefinition;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\RetryScope;
use Maestro\Workflow\ValueObjects\StepKey;

final class SingleJobStepBuilder
{
    private string $displayName;

    /** @var class-string */
    private string $jobClass;

    /** @var list<class-string<StepOutput>> */
    private array $requires = [];

    /** @var class-string<StepOutput>|null */
    private ?string $produces = null;

    private FailurePolicy $failurePolicy = FailurePolicy::FailWorkflow;
    private ?RetryConfiguration $retryConfiguration = null;
    private ?StepTimeout $timeout = null;
    private ?QueueConfiguration $queueConfiguration = null;

    private function __construct(
        private readonly StepKey $key,
    ) {
        $this->displayName = $key->toString();
    }

    public static function create(string $key): self
    {
        return new self(StepKey::fromString($key));
    }

    public function displayName(string $name): self
    {
        $this->displayName = $name;

        return $this;
    }

    /**
     * @param class-string $jobClass
     */
    public function job(string $jobClass): self
    {
        $this->jobClass = $jobClass;

        return $this;
    }

    /**
     * @param class-string<StepOutput> ...$outputClasses
     */
    public function requires(string ...$outputClasses): self
    {
        $this->requires = array_values($outputClasses);

        return $this;
    }

    /**
     * @param class-string<StepOutput> $outputClass
     */
    public function produces(string $outputClass): self
    {
        $this->produces = $outputClass;

        return $this;
    }

    public function onFailure(FailurePolicy $policy): self
    {
        $this->failurePolicy = $policy;

        return $this;
    }

    public function failWorkflow(): self
    {
        return $this->onFailure(FailurePolicy::FailWorkflow);
    }

    public function pauseWorkflow(): self
    {
        return $this->onFailure(FailurePolicy::PauseWorkflow);
    }

    public function retryStep(): self
    {
        return $this->onFailure(FailurePolicy::RetryStep);
    }

    public function skipStep(): self
    {
        return $this->onFailure(FailurePolicy::SkipStep);
    }

    public function retry(
        int $maxAttempts = 3,
        int $delaySeconds = 60,
        float $backoffMultiplier = 2.0,
        int $maxDelaySeconds = 3600,
        RetryScope $scope = RetryScope::All,
    ): self {
        $this->retryConfiguration = RetryConfiguration::create(
            $maxAttempts,
            $delaySeconds,
            $backoffMultiplier,
            $maxDelaySeconds,
            $scope,
        );

        return $this;
    }

    public function noRetry(): self
    {
        $this->retryConfiguration = RetryConfiguration::none();

        return $this;
    }

    public function timeout(?int $stepTimeoutSeconds = null, ?int $jobTimeoutSeconds = null): self
    {
        $this->timeout = StepTimeout::create($stepTimeoutSeconds, $jobTimeoutSeconds);

        return $this;
    }

    public function onQueue(string $queue): self
    {
        $this->queueConfiguration = ($this->queueConfiguration ?? QueueConfiguration::default())
            ->withQueue($queue);

        return $this;
    }

    public function onConnection(string $connection): self
    {
        $this->queueConfiguration = ($this->queueConfiguration ?? QueueConfiguration::default())
            ->withConnection($connection);

        return $this;
    }

    public function delay(int $seconds): self
    {
        $this->queueConfiguration = ($this->queueConfiguration ?? QueueConfiguration::default())
            ->withDelay($seconds);

        return $this;
    }

    public function build(): SingleJobStepDefinition
    {
        return SingleJobStepDefinition::create(
            $this->key,
            $this->displayName,
            $this->jobClass,
            $this->requires,
            $this->produces,
            $this->failurePolicy,
            $this->retryConfiguration,
            $this->timeout,
            $this->queueConfiguration,
        );
    }
}
