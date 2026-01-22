<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http;

/**
 * Result of a trigger authentication attempt.
 */
final readonly class TriggerAuthenticationResult
{
    private function __construct(
        private bool $authenticated,
        private ?string $failureReason,
    ) {}

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }
}
