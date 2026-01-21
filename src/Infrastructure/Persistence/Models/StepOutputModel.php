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
 * @property string $output_class
 * @property string $payload
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 */
final class StepOutputModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_step_outputs';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'step_key',
        'output_class',
        'payload',
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
