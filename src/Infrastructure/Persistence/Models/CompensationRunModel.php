<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $step_key
 * @property string $compensation_job_class
 * @property int $execution_order
 * @property string $status
 * @property int $attempt
 * @property int $max_attempts
 * @property string|null $current_job_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $failure_message
 * @property string|null $failure_trace
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 */
final class CompensationRunModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_compensation_runs';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'step_key',
        'compensation_job_class',
        'execution_order',
        'status',
        'attempt',
        'max_attempts',
        'current_job_id',
        'started_at',
        'finished_at',
        'failure_message',
        'failure_trace',
    ];

    /**
     * @return BelongsTo<WorkflowModel, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowModel::class, 'workflow_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'execution_order' => 'integer',
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
