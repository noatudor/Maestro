<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Builders;

use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Definition\Config\CompensationDefinition;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Definition\Steps\SingleJobStepDefinition;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Enums\RetryScope;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
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

    private ?StepTimeout $stepTimeout = null;

    private ?QueueConfiguration $queueConfiguration = null;

    private ?CompensationDefinition $compensationDefinition = null;

    /** @var class-string<StepCondition>|null */
    private ?string $conditionClass = null;

    /** @var class-string<TerminationCondition>|null */
    private ?string $terminationConditionClass = null;

    private ?BranchDefinition $branchDefinition = null;

    private ?PauseTriggerDefinition $pauseTriggerDefinition = null;

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
     * The compensation job is executed in reverse order when compensation is triggered.
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

    /**
     * Pause the workflow after this step completes, awaiting an external trigger.
     *
     * @param string $triggerKey Unique identifier for this trigger
     * @param int $timeoutSeconds Maximum time to wait for trigger (default: 7 days)
     * @param TriggerTimeoutPolicy $triggerTimeoutPolicy What to do when timeout is reached
     * @param class-string<ResumeCondition>|null $resumeConditionClass Optional condition to validate payload
     * @param class-string<StepOutput>|null $payloadOutputClass Optional output class to store payload as
     */
    public function pauseAfter(
        string $triggerKey,
        int $timeoutSeconds = 604800,
        TriggerTimeoutPolicy $triggerTimeoutPolicy = TriggerTimeoutPolicy::FailWorkflow,
        ?string $resumeConditionClass = null,
        ?string $payloadOutputClass = null,
    ): self {
        $this->pauseTriggerDefinition = PauseTriggerDefinition::create(
            triggerKey: $triggerKey,
            timeoutSeconds: $timeoutSeconds,
            resumeConditionClass: $resumeConditionClass,
            payloadOutputClass: $payloadOutputClass,
            timeoutPolicy: $triggerTimeoutPolicy,
        );

        return $this;
    }

    /**
     * Pause the workflow after this step and auto-resume after a scheduled time.
     *
     * Use this for cooling-off periods, mandatory review windows, rate limiting, etc.
     *
     * @param string $triggerKey Unique identifier for this trigger
     * @param int $resumeAfterSeconds Time to wait before auto-resuming
     */
    public function pauseAfterForDuration(
        string $triggerKey,
        int $resumeAfterSeconds,
    ): self {
        $this->pauseTriggerDefinition = PauseTriggerDefinition::scheduledResume(
            triggerKey: $triggerKey,
            resumeAfterSeconds: $resumeAfterSeconds,
        );

        return $this;
    }

    /**
     * Define a custom pause trigger configuration.
     */
    public function pauseAfterWithConfig(PauseTriggerDefinition $pauseTriggerDefinition): self
    {
        $this->pauseTriggerDefinition = $pauseTriggerDefinition;

        return $this;
    }

    public function build(): SingleJobStepDefinition
    {
        return SingleJobStepDefinition::create(
            $this->stepKey,
            $this->displayName,
            $this->jobClass,
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
            $this->pauseTriggerDefinition,
        );
    }
}
