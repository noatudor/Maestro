<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Maestro\Workflow\Infrastructure\Persistence\QueryBuilders\JobLedgerQueryBuilder;
use Override;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $step_run_id
 * @property string $job_uuid
 * @property string $job_class
 * @property string $queue
 * @property string $status
 * @property int $attempt
 * @property CarbonImmutable $dispatched_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $runtime_ms
 * @property string|null $failure_class
 * @property string|null $failure_message
 * @property string|null $failure_trace
 * @property string|null $worker_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 * @property-read StepRunModel $stepRun
 */
final class JobLedgerModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_job_ledger';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'step_run_id',
        'job_uuid',
        'job_class',
        'queue',
        'status',
        'attempt',
        'dispatched_at',
        'started_at',
        'finished_at',
        'runtime_ms',
        'failure_class',
        'failure_message',
        'failure_trace',
        'worker_id',
    ];

    /**
     * @param Builder $query
     */
    #[Override]
    public function newEloquentBuilder($query): JobLedgerQueryBuilder
    {
        return new JobLedgerQueryBuilder($query);
    }

    /**
     * @return BelongsTo<WorkflowModel, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowModel::class, 'workflow_id', 'id');
    }

    /**
     * @return BelongsTo<StepRunModel, $this>
     */
    public function stepRun(): BelongsTo
    {
        return $this->belongsTo(StepRunModel::class, 'step_run_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'runtime_ms' => 'integer',
            'dispatched_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
