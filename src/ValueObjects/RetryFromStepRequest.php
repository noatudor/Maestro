<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Enums\RetryMode;

/**
 * Request object for retry-from-step operations.
 */
final readonly class RetryFromStepRequest
{
    private function __construct(
        public WorkflowId $workflowId,
        public StepKey $retryFromStepKey,
        public RetryMode $retryMode,
        public ?string $initiatedBy,
        public ?string $reason,
    ) {}

    public static function create(
        WorkflowId $workflowId,
        StepKey $retryFromStepKey,
        RetryMode $retryMode = RetryMode::RetryOnly,
        ?string $initiatedBy = null,
        ?string $reason = null,
    ): self {
        return new self(
            workflowId: $workflowId,
            retryFromStepKey: $retryFromStepKey,
            retryMode: $retryMode,
            initiatedBy: $initiatedBy,
            reason: $reason,
        );
    }

    public function requiresCompensation(): bool
    {
        return $this->retryMode->requiresCompensation();
    }
}
