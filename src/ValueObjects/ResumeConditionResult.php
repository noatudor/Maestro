<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

/**
 * Result of evaluating a resume condition.
 */
final readonly class ResumeConditionResult
{
    private function __construct(
        private bool $shouldResume,
        private ?string $rejectionReason,
    ) {}

    public static function resume(): self
    {
        return new self(
            shouldResume: true,
            rejectionReason: null,
        );
    }

    public static function reject(string $reason): self
    {
        return new self(
            shouldResume: false,
            rejectionReason: $reason,
        );
    }

    public function shouldResume(): bool
    {
        return $this->shouldResume;
    }

    public function rejectionReason(): ?string
    {
        return $this->rejectionReason;
    }
}
