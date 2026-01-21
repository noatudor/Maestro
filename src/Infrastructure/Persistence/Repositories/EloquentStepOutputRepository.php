<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Connection;
use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\Contracts\OutputSerializer;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Exceptions\SerializationException;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepOutputModel;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

final readonly class EloquentStepOutputRepository implements StepOutputRepository
{
    public function __construct(
        private OutputSerializer $serializer,
        private Connection $connection,
    ) {}

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    public function find(WorkflowId $workflowId, string $outputClass): ?StepOutput
    {
        $model = StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('output_class', $outputClass)
            ->first();

        if ($model === null) {
            return null;
        }

        try {
            return $this->serializer->deserialize($model->payload, $outputClass);
        } catch (SerializationException) {
            return null;
        }
    }

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    public function findForUpdate(WorkflowId $workflowId, string $outputClass): ?StepOutput
    {
        $model = StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('output_class', $outputClass)
            ->lockForUpdate()
            ->first();

        if ($model === null) {
            return null;
        }

        try {
            return $this->serializer->deserialize($model->payload, $outputClass);
        } catch (SerializationException) {
            return null;
        }
    }

    /**
     * @param class-string<StepOutput> $outputClass
     */
    public function has(WorkflowId $workflowId, string $outputClass): bool
    {
        return StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('output_class', $outputClass)
            ->exists();
    }

    public function save(WorkflowId $workflowId, StepOutput $output): void
    {
        $outputClass = $output::class;
        $stepKey = $output->stepKey();

        StepOutputModel::query()->updateOrCreate(
            [
                'workflow_id' => $workflowId->value,
                'output_class' => $outputClass,
            ],
            [
                'id' => Uuid::uuid7()->toString(),
                'step_key' => $stepKey->value,
                'payload' => $this->serializer->serialize($output),
            ],
        );
    }

    public function saveWithAtomicMerge(WorkflowId $workflowId, MergeableOutput $output): void
    {
        $outputClass = $output::class;

        $this->connection->transaction(function () use ($workflowId, $output, $outputClass): void {
            $existing = $this->findForUpdate($workflowId, $outputClass);

            $finalOutput = $output;
            if ($existing instanceof MergeableOutput) {
                $finalOutput = $existing->mergeWith($output);
            }

            $this->save($workflowId, $finalOutput);
        });
    }

    /**
     * @return list<StepOutput>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array
    {
        $models = StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->get();

        $outputs = [];
        foreach ($models as $model) {
            /** @var class-string<StepOutput> $outputClass */
            $outputClass = $model->output_class;

            try {
                $outputs[] = $this->serializer->deserialize($model->payload, $outputClass);
            } catch (SerializationException) {
                continue;
            }
        }

        return $outputs;
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->delete();
    }

    /**
     * @return list<StepOutputModel>
     */
    public function findModelsByWorkflowId(WorkflowId $workflowId): array
    {
        return array_values(StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->get()
            ->all());
    }

    /**
     * @return list<StepOutput>
     */
    public function findByStepKey(WorkflowId $workflowId, string $stepKey): array
    {
        $models = StepOutputModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('step_key', $stepKey)
            ->get();

        $outputs = [];
        foreach ($models as $model) {
            /** @var class-string<StepOutput> $outputClass */
            $outputClass = $model->output_class;

            try {
                $outputs[] = $this->serializer->deserialize($model->payload, $outputClass);
            } catch (SerializationException) {
                continue;
            }
        }

        return $outputs;
    }
}
