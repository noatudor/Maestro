<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Maestro\Workflow\Infrastructure\Persistence\QueryBuilders\StepRunQueryBuilder;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $step_key
 * @property int $attempt
 * @property string $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int $completed_job_count
 * @property int $failed_job_count
 * @property int $total_job_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 * @property-read Collection<int, JobLedgerModel> $jobs
 */
final class StepRunModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_step_runs';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'step_key',
        'attempt',
        'status',
        'started_at',
        'finished_at',
        'failure_code',
        'failure_message',
        'completed_job_count',
        'failed_job_count',
        'total_job_count',
    ];

    /**
     * @param \Illuminate\Database\Query\Builder $query
     */
    public function newEloquentBuilder($query): StepRunQueryBuilder
    {
        return new StepRunQueryBuilder($query);
    }

    /**
     * @return BelongsTo<WorkflowModel, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowModel::class, 'workflow_id', 'id');
    }

    /**
     * @return HasMany<JobLedgerModel, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(JobLedgerModel::class, 'step_run_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'completed_job_count' => 'integer',
            'failed_job_count' => 'integer',
            'total_job_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
