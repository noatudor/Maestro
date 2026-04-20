<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Workflows;

use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class FetchDataStep implements SingleJobStep
{
    public function __construct(
        private WorkflowContext $context,
    ) {}

    public function handle(): StepOutput
    {
        $batchSize = $this->context->get('batch_size', 10);

        return new FetchDataOutput(
            totalRecords: $batchSize,
            dataChunks: array_map(
                fn ($i) => ['id' => $i, 'data' => "record-{$i}"],
                range(1, $batchSize)
            ),
        );
    }
}

final readonly class FetchDataOutput implements StepOutput
{
    public function __construct(
        public int $totalRecords,
        public array $dataChunks,
    ) {}
}

final readonly class ProcessChunkStep implements FanOutStep
{
    public function __construct(
        private FetchDataOutput $fetchOutput,
    ) {}

    /**
     * @return iterable<ProcessChunkJob>
     */
    public function jobs(): iterable
    {
        foreach ($this->fetchOutput->dataChunks as $chunk) {
            yield new ProcessChunkJob($chunk);
        }
    }
}

final readonly class ProcessChunkJob
{
    public function __construct(
        public array $chunk,
    ) {}

    public function handle(): ProcessChunkOutput
    {
        return new ProcessChunkOutput(
            chunkId: $this->chunk['id'],
            processedData: strtoupper($this->chunk['data']),
            success: true,
        );
    }
}

final readonly class ProcessChunkOutput implements StepOutput, MergeableOutput
{
    public function __construct(
        public int $chunkId,
        public string $processedData,
        public bool $success,
    ) {}

    public function merge(StepOutput $other): self
    {
        return $this;
    }
}

final readonly class AggregateResultsStep implements SingleJobStep
{
    /** @var array<ProcessChunkOutput> */
    private array $chunkOutputs;

    public function __construct(
        ProcessChunkOutput ...$chunkOutputs,
    ) {
        $this->chunkOutputs = $chunkOutputs;
    }

    public function handle(): StepOutput
    {
        $successCount = count(array_filter(
            $this->chunkOutputs,
            fn (ProcessChunkOutput $output) => $output->success
        ));

        return new AggregateResultsOutput(
            totalProcessed: count($this->chunkOutputs),
            successCount: $successCount,
            failureCount: count($this->chunkOutputs) - $successCount,
        );
    }
}

final readonly class AggregateResultsOutput implements StepOutput
{
    public function __construct(
        public int $totalProcessed,
        public int $successCount,
        public int $failureCount,
    ) {}
}
