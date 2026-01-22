<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;

/**
 * Configuration for automatic pause trigger after step completion.
 *
 * Defines the trigger key, timeout settings, resume conditions,
 * and payload handling for paused workflows awaiting external action.
 */
final readonly class PauseTriggerDefinition
{
    /**
     * @param class-string<ResumeCondition>|null $resumeConditionClass
     * @param class-string<StepOutput>|null $payloadOutputClass
     */
    private function __construct(
        public string $triggerKey,
        public int $timeoutSeconds,
        public TriggerTimeoutPolicy $timeoutPolicy,
        public ?int $scheduledResumeSeconds,
        public ?string $resumeConditionClass,
        public ?string $payloadOutputClass,
        public ?int $reminderIntervalSeconds,
    ) {}

    /**
     * @param class-string<ResumeCondition>|null $resumeConditionClass
     * @param class-string<StepOutput>|null $payloadOutputClass
     */
    public static function create(
        string $triggerKey,
        int $timeoutSeconds = 604800,
        TriggerTimeoutPolicy $triggerTimeoutPolicy = TriggerTimeoutPolicy::FailWorkflow,
        ?int $scheduledResumeSeconds = null,
        ?string $resumeConditionClass = null,
        ?string $payloadOutputClass = null,
        ?int $reminderIntervalSeconds = null,
    ): self {
        return new self(
            triggerKey: $triggerKey,
            timeoutSeconds: max(60, $timeoutSeconds),
            timeoutPolicy: $triggerTimeoutPolicy,
            scheduledResumeSeconds: $scheduledResumeSeconds !== null ? max(60, $scheduledResumeSeconds) : null,
            resumeConditionClass: $resumeConditionClass,
            payloadOutputClass: $payloadOutputClass,
            reminderIntervalSeconds: $reminderIntervalSeconds !== null ? max(60, $reminderIntervalSeconds) : null,
        );
    }

    /**
     * Create a trigger that auto-resumes after a scheduled time.
     *
     * Use this for cooling-off periods, mandatory review windows, etc.
     */
    public static function scheduledResume(
        string $triggerKey,
        int $resumeAfterSeconds,
    ): self {
        return new self(
            triggerKey: $triggerKey,
            timeoutSeconds: $resumeAfterSeconds,
            timeoutPolicy: TriggerTimeoutPolicy::AutoResume,
            scheduledResumeSeconds: $resumeAfterSeconds,
            resumeConditionClass: null,
            payloadOutputClass: null,
            reminderIntervalSeconds: null,
        );
    }

    /**
     * Check if this trigger expects external input.
     */
    public function expectsExternalTrigger(): bool
    {
        return $this->scheduledResumeSeconds === null;
    }

    /**
     * Check if this trigger will auto-resume after scheduled time.
     */
    public function hasScheduledResume(): bool
    {
        return $this->scheduledResumeSeconds !== null;
    }

    /**
     * Check if a resume condition must be evaluated.
     */
    public function hasResumeCondition(): bool
    {
        return $this->resumeConditionClass !== null;
    }

    /**
     * Check if trigger payload should be stored as step output.
     */
    public function hasPayloadOutput(): bool
    {
        return $this->payloadOutputClass !== null;
    }

    /**
     * Check if reminders should be sent on timeout.
     */
    public function hasReminders(): bool
    {
        return $this->reminderIntervalSeconds !== null
            && $this->timeoutPolicy === TriggerTimeoutPolicy::SendReminder;
    }

    public function withTimeout(int $seconds): self
    {
        return new self(
            $this->triggerKey,
            max(60, $seconds),
            $this->timeoutPolicy,
            $this->scheduledResumeSeconds,
            $this->resumeConditionClass,
            $this->payloadOutputClass,
            $this->reminderIntervalSeconds,
        );
    }

    public function withTimeoutPolicy(TriggerTimeoutPolicy $triggerTimeoutPolicy): self
    {
        return new self(
            $this->triggerKey,
            $this->timeoutSeconds,
            $triggerTimeoutPolicy,
            $this->scheduledResumeSeconds,
            $this->resumeConditionClass,
            $this->payloadOutputClass,
            $this->reminderIntervalSeconds,
        );
    }

    /**
     * @param class-string<ResumeCondition> $conditionClass
     */
    public function withResumeCondition(string $conditionClass): self
    {
        return new self(
            $this->triggerKey,
            $this->timeoutSeconds,
            $this->timeoutPolicy,
            $this->scheduledResumeSeconds,
            $conditionClass,
            $this->payloadOutputClass,
            $this->reminderIntervalSeconds,
        );
    }

    /**
     * @param class-string<StepOutput> $outputClass
     */
    public function withPayloadOutput(string $outputClass): self
    {
        return new self(
            $this->triggerKey,
            $this->timeoutSeconds,
            $this->timeoutPolicy,
            $this->scheduledResumeSeconds,
            $this->resumeConditionClass,
            $outputClass,
            $this->reminderIntervalSeconds,
        );
    }

    public function withReminderInterval(int $seconds): self
    {
        return new self(
            $this->triggerKey,
            $this->timeoutSeconds,
            $this->timeoutPolicy,
            $this->scheduledResumeSeconds,
            $this->resumeConditionClass,
            $this->payloadOutputClass,
            max(60, $seconds),
        );
    }

    public function equals(self $other): bool
    {
        return $this->triggerKey === $other->triggerKey
            && $this->timeoutSeconds === $other->timeoutSeconds
            && $this->timeoutPolicy === $other->timeoutPolicy
            && $this->scheduledResumeSeconds === $other->scheduledResumeSeconds
            && $this->resumeConditionClass === $other->resumeConditionClass
            && $this->payloadOutputClass === $other->payloadOutputClass
            && $this->reminderIntervalSeconds === $other->reminderIntervalSeconds;
    }
}
