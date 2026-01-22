<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Maestro\Workflow\Infrastructure\Persistence\QueryBuilders\StepRunQueryBuilder;
use Override;

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
 * @property string|null $superseded_by_id
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $retry_source
 * @property string|null $skip_reason
 * @property string|null $skip_message
 * @property string|null $branch_key
 * @property int $poll_attempt_count
 * @property CarbonImmutable|null $next_poll_at
 * @property CarbonImmutable|null $poll_started_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 * @property-read Collection<int, JobLedgerModel> $jobs
 * @property-read StepRunModel|null $supersededBy
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
        'superseded_by_id',
        'superseded_at',
        'retry_source',
        'skip_reason',
        'skip_message',
        'branch_key',
        'poll_attempt_count',
        'next_poll_at',
        'poll_started_at',
    ];

    /**
     * @param Builder $query
     */
    #[Override]
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
     * @return BelongsTo<StepRunModel, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id', 'id');
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
            'poll_attempt_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'next_poll_at' => 'immutable_datetime',
            'poll_started_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
