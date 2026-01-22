<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Enums\WorkflowState;

/**
 * The result of evaluating a termination condition.
 */
final readonly class TerminationResult
{
    private function __construct(
        private bool $shouldTerminate,
        private ?WorkflowState $workflowState,
        private ?string $reason,
    ) {}

    /**
     * Create a result indicating the workflow should continue.
     */
    public static function continue(): self
    {
        return new self(
            shouldTerminate: false,
            workflowState: null,
            reason: null,
        );
    }

    /**
     * Create a result indicating the workflow should terminate.
     */
    public static function terminate(WorkflowState $workflowState, string $reason): self
    {
        return new self(
            shouldTerminate: true,
            workflowState: $workflowState,
            reason: $reason,
        );
    }

    /**
     * Whether the workflow should terminate early.
     */
    public function shouldTerminate(): bool
    {
        return $this->shouldTerminate;
    }

    /**
     * Whether the workflow should continue.
     */
    public function shouldContinue(): bool
    {
        return ! $this->shouldTerminate;
    }

    /**
     * The terminal state to transition to.
     */
    public function terminalState(): ?WorkflowState
    {
        return $this->workflowState;
    }

    /**
     * The reason for termination.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
