<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Maestro\Workflow\Infrastructure\Persistence\QueryBuilders\WorkflowQueryBuilder;
use Override;

/**
 * @property string $id
 * @property string $definition_key
 * @property string $definition_version
 * @property string $state
 * @property string|null $current_step_key
 * @property CarbonImmutable|null $paused_at
 * @property string|null $paused_reason
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $locked_by
 * @property CarbonImmutable|null $locked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, StepRunModel> $stepRuns
 * @property-read Collection<int, JobLedgerModel> $jobs
 * @property-read Collection<int, StepOutputModel> $outputs
 */
final class WorkflowModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_workflows';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'definition_key',
        'definition_version',
        'state',
        'current_step_key',
        'paused_at',
        'paused_reason',
        'failed_at',
        'failure_code',
        'failure_message',
        'succeeded_at',
        'cancelled_at',
        'locked_by',
        'locked_at',
    ];

    /**
     * @param Builder $query
     */
    #[Override]
    public function newEloquentBuilder($query): WorkflowQueryBuilder
    {
        return new WorkflowQueryBuilder($query);
    }

    /**
     * @return HasMany<StepRunModel, $this>
     */
    public function stepRuns(): HasMany
    {
        return $this->hasMany(StepRunModel::class, 'workflow_id', 'id');
    }

    /**
     * @return HasMany<JobLedgerModel, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(JobLedgerModel::class, 'workflow_id', 'id');
    }

    /**
     * @return HasMany<StepOutputModel, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(StepOutputModel::class, 'workflow_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paused_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
