<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $trigger_key
 * @property string $payload
 * @property CarbonImmutable $received_at
 * @property string|null $source_ip
 * @property string|null $source_identifier
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 */
final class TriggerPayloadModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_trigger_payloads';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'trigger_key',
        'payload',
        'received_at',
        'source_ip',
        'source_identifier',
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
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
