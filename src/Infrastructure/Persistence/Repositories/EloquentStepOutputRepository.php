<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepOutputModel;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

final readonly class EloquentStepOutputRepository implements StepOutputRepository
{
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

        return $this->deserialize($model->payload, $outputClass);
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
                'payload' => $this->serialize($output),
            ],
        );
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
            $output = $this->deserializeAny($model->payload, $model->output_class);
            if ($output !== null) {
                $outputs[] = $output;
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
            $output = $this->deserializeAny($model->payload, $model->output_class);
            if ($output !== null) {
                $outputs[] = $output;
            }
        }

        return $outputs;
    }

    private function serialize(StepOutput $output): string
    {
        return serialize($output);
    }

    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    private function deserialize(string $payload, string $outputClass): ?StepOutput
    {
        $output = unserialize($payload);

        if (! $output instanceof $outputClass) {
            return null;
        }

        return $output;
    }

    private function deserializeAny(string $payload, string $outputClass): ?StepOutput
    {
        $output = unserialize($payload);

        if (! $output instanceof StepOutput) {
            return null;
        }

        if ($output::class !== $outputClass) {
            return null;
        }

        return $output;
    }
}
