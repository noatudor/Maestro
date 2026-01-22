<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

/**
 * Evaluates which branches to follow at a branch point.
 *
 * Branch conditions are resolved via the Laravel container, allowing
 * dependency injection of services needed for branch evaluation.
 */
interface BranchCondition
{
    /**
     * Evaluate which branch keys should be followed.
     *
     * For exclusive branches, return exactly one key.
     * For inclusive branches, return one or more keys.
     *
     * @return list<string> The branch keys to follow
     */
    public function evaluate(StepOutputReader $stepOutputReader): array;
}
