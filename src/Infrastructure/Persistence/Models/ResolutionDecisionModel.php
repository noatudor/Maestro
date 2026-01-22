<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $decision_type
 * @property string|null $decided_by
 * @property string|null $reason
 * @property string|null $retry_from_step_key
 * @property array<int, string>|null $compensate_step_keys
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 */
final class ResolutionDecisionModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_resolution_decisions';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'decision_type',
        'decided_by',
        'reason',
        'retry_from_step_key',
        'compensate_step_keys',
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
            'compensate_step_keys' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
