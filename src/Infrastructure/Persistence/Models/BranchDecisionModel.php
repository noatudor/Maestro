<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $branch_point_key
 * @property string $condition_class
 * @property array<string> $selected_branches
 * @property CarbonImmutable $evaluated_at
 * @property array<string, mixed>|null $input_summary
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkflowModel $workflow
 */
final class BranchDecisionModel extends Model
{
    public $incrementing = false;

    protected $table = 'maestro_branch_decisions';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'branch_point_key',
        'condition_class',
        'selected_branches',
        'evaluated_at',
        'input_summary',
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
            'selected_branches' => 'array',
            'input_summary' => 'array',
            'evaluated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
