<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Builders;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Definition\Config\CompensationDefinition;
use Maestro\Workflow\Definition\Config\PollingConfiguration;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Definition\Steps\PollingStepDefinition;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\PollTimeoutPolicy;
use Maestro\Workflow\Enums\RetryScope;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\ValueObjects\StepKey;

final class PollingStepBuilder
{
    private string $displayName;

    /** @var class-string */
    private string $jobClass;

    private ?PollingConfiguration $pollingConfiguration = null;

    /** @var list<class-string<StepOutput>> */
    private array $requires = [];

    /** @var class-string<StepOutput>|null */
    private ?string $produces = null;

    private FailurePolicy $failurePolicy = FailurePolicy::FailWorkflow;

    private ?RetryConfiguration $retryConfiguration = null;

    private ?StepTimeout $stepTimeout = null;

    private ?QueueConfiguration $queueConfiguration = null;

    private ?CompensationDefinition $compensationDefinition = null;

    /** @var class-string<StepCondition>|null */
    private ?string $conditionClass = null;

    /** @var class-string<TerminationCondition>|null */
    private ?string $terminationConditionClass = null;

    private ?BranchDefinition $branchDefinition = null;

    private function __construct(
        private readonly StepKey $stepKey,
    ) {
        $this->displayName = $stepKey->toString();
    }

    /**
     * @throws InvalidStepKeyException
     */
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
     * Configure polling interval and limits.
     *
     * @param int $intervalSeconds Base polling interval in seconds
     * @param int $maxDurationSeconds Maximum time to poll before timeout
     * @param int|null $maxAttempts Maximum number of poll attempts (optional)
     * @param float $backoffMultiplier Multiplier for exponential backoff (1.0 = no backoff)
     * @param int|null $maxIntervalSeconds Maximum interval when using backoff
     */
    public function polling(
        int $intervalSeconds = 300,
        int $maxDurationSeconds = 86400,
        ?int $maxAttempts = null,
        float $backoffMultiplier = 1.0,
        ?int $maxIntervalSeconds = null,
    ): self {
        $this->pollingConfiguration = PollingConfiguration::create(
            intervalSeconds: $intervalSeconds,
            maxDurationSeconds: $maxDurationSeconds,
            maxAttempts: $maxAttempts,
            backoffMultiplier: $backoffMultiplier,
            maxIntervalSeconds: $maxIntervalSeconds,
        );

        return $this;
    }

    /**
     * Set the poll timeout policy.
     */
    public function onPollTimeout(PollTimeoutPolicy $pollTimeoutPolicy): self
    {
        $this->pollingConfiguration = ($this->pollingConfiguration ?? PollingConfiguration::default())
            ->withTimeoutPolicy($pollTimeoutPolicy);

        return $this;
    }

    /**
     * Fail the workflow when polling times out.
     */
    public function failOnPollTimeout(): self
    {
        return $this->onPollTimeout(PollTimeoutPolicy::FailWorkflow);
    }

    /**
     * Pause the workflow when polling times out.
     */
    public function pauseOnPollTimeout(): self
    {
        return $this->onPollTimeout(PollTimeoutPolicy::PauseWorkflow);
    }

    /**
     * Continue with a default output when polling times out.
     *
     * @param class-string<StepOutput> $outputClass
     */
    public function continueWithDefaultOnTimeout(string $outputClass): self
    {
        $this->pollingConfiguration = ($this->pollingConfiguration ?? PollingConfiguration::default())
            ->withTimeoutPolicy(PollTimeoutPolicy::ContinueWithDefault)
            ->withDefaultOutput($outputClass);

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

    public function onFailure(FailurePolicy $failurePolicy): self
    {
        $this->failurePolicy = $failurePolicy;

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
        RetryScope $retryScope = RetryScope::All,
    ): self {
        $this->retryConfiguration = RetryConfiguration::create(
            $maxAttempts,
            $delaySeconds,
            $backoffMultiplier,
            $maxDelaySeconds,
            $retryScope,
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
        $this->stepTimeout = StepTimeout::create($stepTimeoutSeconds, $jobTimeoutSeconds);

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

    /**
     * Define a compensation job for this step.
     *
     * @param class-string $jobClass
     */
    public function compensation(
        string $jobClass,
        ?int $timeoutSeconds = null,
        ?RetryConfiguration $retryConfiguration = null,
        ?QueueConfiguration $queueConfiguration = null,
    ): self {
        $this->compensationDefinition = CompensationDefinition::create(
            $jobClass,
            $timeoutSeconds,
            $retryConfiguration,
            $queueConfiguration,
        );

        return $this;
    }

    /**
     * Define a condition that must be met for this step to execute.
     *
     * @param class-string<StepCondition> $conditionClass
     */
    public function when(string $conditionClass): self
    {
        $this->conditionClass = $conditionClass;

        return $this;
    }

    /**
     * Define a termination condition checked after step completion.
     *
     * @param class-string<TerminationCondition> $conditionClass
     */
    public function terminateWhen(string $conditionClass): self
    {
        $this->terminationConditionClass = $conditionClass;

        return $this;
    }

    /**
     * Define this step as a branch point.
     */
    public function branch(BranchDefinition $branchDefinition): self
    {
        $this->branchDefinition = $branchDefinition;

        return $this;
    }

    public function build(): PollingStepDefinition
    {
        return PollingStepDefinition::create(
            $this->stepKey,
            $this->displayName,
            $this->jobClass,
            $this->pollingConfiguration,
            $this->requires,
            $this->produces,
            $this->failurePolicy,
            $this->retryConfiguration,
            $this->stepTimeout,
            $this->queueConfiguration,
            $this->compensationDefinition,
            $this->conditionClass,
            $this->terminationConditionClass,
            $this->branchDefinition,
        );
    }
}
