<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Enums\SkipReason;

/**
 * The result of evaluating a step condition.
 */
final readonly class ConditionResult
{
    private function __construct(
        private bool $shouldExecute,
        private ?SkipReason $skipReason,
        private ?string $skipMessage,
    ) {}

    /**
     * Create a result indicating the step should execute.
     */
    public static function execute(): self
    {
        return new self(
            shouldExecute: true,
            skipReason: null,
            skipMessage: null,
        );
    }

    /**
     * Create a result indicating the step should be skipped.
     */
    public static function skip(SkipReason $skipReason, ?string $message = null): self
    {
        return new self(
            shouldExecute: false,
            skipReason: $skipReason,
            skipMessage: $message,
        );
    }

    /**
     * Whether the step should execute.
     */
    public function shouldExecute(): bool
    {
        return $this->shouldExecute;
    }

    /**
     * Whether the step should be skipped.
     */
    public function shouldSkip(): bool
    {
        return ! $this->shouldExecute;
    }

    /**
     * The reason for skipping, if applicable.
     */
    public function skipReason(): ?SkipReason
    {
        return $this->skipReason;
    }

    /**
     * Optional message explaining the skip reason.
     */
    public function skipMessage(): ?string
    {
        return $this->skipMessage;
    }
}
