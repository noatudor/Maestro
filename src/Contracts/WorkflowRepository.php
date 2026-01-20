<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface WorkflowRepository
{
    public function find(WorkflowId $id): ?object;

    public function save(object $workflow): void;

    public function delete(WorkflowId $id): void;

    /**
     * @return array<string, object>
     */
    public function findByState(WorkflowState $state): array;
}
