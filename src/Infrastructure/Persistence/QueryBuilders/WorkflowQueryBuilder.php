<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\QueryBuilders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Infrastructure\Persistence\Models\WorkflowModel;

/**
 * @extends Builder<WorkflowModel>
 */
final class WorkflowQueryBuilder extends Builder
{
    public function pending(): self
    {
        return $this->where('state', WorkflowState::Pending->value);
    }

    public function running(): self
    {
        return $this->where('state', WorkflowState::Running->value);
    }

    public function paused(): self
    {
        return $this->where('state', WorkflowState::Paused->value);
    }

    public function failed(): self
    {
        return $this->where('state', WorkflowState::Failed->value);
    }

    public function succeeded(): self
    {
        return $this->where('state', WorkflowState::Succeeded->value);
    }

    public function cancelled(): self
    {
        return $this->where('state', WorkflowState::Cancelled->value);
    }

    public function active(): self
    {
        return $this->whereIn('state', [
            WorkflowState::Pending->value,
            WorkflowState::Running->value,
            WorkflowState::Paused->value,
        ]);
    }

    public function terminal(): self
    {
        return $this->whereIn('state', [
            WorkflowState::Succeeded->value,
            WorkflowState::Failed->value,
            WorkflowState::Cancelled->value,
        ]);
    }

    public function staleRunning(CarbonImmutable $threshold): self
    {
        return $this->running()->where('updated_at', '<', $threshold);
    }

    public function stalePaused(CarbonImmutable $threshold): self
    {
        return $this->paused()->where('paused_at', '<', $threshold);
    }

    public function lockedBy(string $lockId): self
    {
        return $this->where('locked_by', $lockId);
    }

    public function unlocked(): self
    {
        return $this->whereNull('locked_by');
    }

    public function locked(): self
    {
        return $this->whereNotNull('locked_by');
    }

    public function staleLocked(CarbonImmutable $threshold): self
    {
        return $this->locked()->where('locked_at', '<', $threshold);
    }

    public function forDefinition(string $definitionKey): self
    {
        return $this->where('definition_key', $definitionKey);
    }

    public function forDefinitionVersion(string $definitionKey, string $version): self
    {
        return $this->forDefinition($definitionKey)->where('definition_version', $version);
    }

    public function createdBetween(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return $this->whereBetween('created_at', [$start, $end]);
    }

    public function createdBefore(CarbonImmutable $date): self
    {
        return $this->where('created_at', '<', $date);
    }

    public function createdAfter(CarbonImmutable $date): self
    {
        return $this->where('created_at', '>', $date);
    }
}
