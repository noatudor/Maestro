<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fakes;

use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryWorkflowRepository implements WorkflowRepository
{
    /** @var array<string, object> */
    private array $workflows = [];

    public function find(WorkflowId $id): ?object
    {
        return $this->workflows[$id->value] ?? null;
    }

    public function save(object $workflow): void
    {
        $this->workflows[$workflow->id->value] = $workflow;
    }

    public function delete(WorkflowId $id): void
    {
        unset($this->workflows[$id->value]);
    }

    /**
     * @return array<string, object>
     */
    public function all(): array
    {
        return $this->workflows;
    }

    /**
     * @return array<string, object>
     */
    public function findByState(WorkflowState $state): array
    {
        return array_filter(
            $this->workflows,
            static fn (object $workflow): bool => $workflow->state === $state,
        );
    }

    public function count(): int
    {
        return count($this->workflows);
    }

    public function clear(): void
    {
        $this->workflows = [];
    }
}
