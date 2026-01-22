<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $step_run_id
 * @property int $attempt_number
 * @property string|null $job_id
 * @property bool $result_complete
 * @property bool $result_continue
 * @property int|null $next_interval_seconds
 * @property CarbonImmutable $executed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StepRunModel $stepRun
 */
final class PollAttemptModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_poll_attempts';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'step_run_id',
        'attempt_number',
        'job_id',
        'result_complete',
        'result_continue',
        'next_interval_seconds',
        'executed_at',
    ];

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
            'attempt_number' => 'integer',
            'result_complete' => 'boolean',
            'result_continue' => 'boolean',
            'next_interval_seconds' => 'integer',
            'executed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
