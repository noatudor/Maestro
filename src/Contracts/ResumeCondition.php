<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\ValueObjects\TriggerPayload;

/**
 * Evaluates whether a trigger payload meets conditions to resume the workflow.
 *
 * Resume conditions are resolved via the Laravel container, allowing
 * dependency injection of services needed for condition evaluation.
 */
interface ResumeCondition
{
    /**
     * Evaluate whether the workflow should resume based on the trigger payload.
     *
     * @return bool True if the workflow should resume, false if the payload does not meet conditions
     */
    public function shouldResume(TriggerPayload $triggerPayload, StepOutputReader $stepOutputReader): bool;

    /**
     * Get the reason why the condition was not met.
     *
     * Called only when shouldResume() returns false.
     */
    public function rejectionReason(): string;
}
