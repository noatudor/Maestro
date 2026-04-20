# Data Pipeline Workflow

This example demonstrates an ETL (Extract, Transform, Load) data pipeline workflow with fan-out processing, polling for external completion, and error recovery.

## Workflow Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Data Pipeline Workflow                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────┐                                                           │
│   │  validate   │  Validate source configuration                            │
│   │  _source    │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  extract    │  Extract data from source (pagination)                    │
│   │  _data      │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────────────────────────────────────────────────┐              │
│   │              transform_records (Fan-Out)                 │              │
│   │  ┌─────┐  ┌─────┐  ┌─────┐  ┌─────┐  ┌─────┐          │              │
│   │  │Rec 1│  │Rec 2│  │Rec 3│  │Rec 4│  │ ... │          │              │
│   │  └─────┘  └─────┘  └─────┘  └─────┘  └─────┘          │              │
│   └──────────────────────┬──────────────────────────────────┘              │
│                          │                                                   │
│                          ▼                                                   │
│   ┌─────────────┐                                                           │
│   │  load_data  │  Bulk load into destination                               │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  verify     │  Poll to verify data integrity                            │
│   │  _integrity │  (Polling Step)                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  generate   │  Create pipeline report                                   │
│   │  _report    │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  notify     │  Send completion notification                             │
│   └─────────────┘                                                           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Workflow Definition

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\DataQualityCheckCondition;
use App\ContextLoaders\PipelineContextLoader;
use App\Jobs\Pipeline\{
    ValidateSourceJob,
    ExtractDataJob,
    TransformRecordJob,
    LoadDataJob,
    VerifyIntegrityJob,
    GenerateReportJob,
    SendNotificationJob,
    CleanupStagingJob,
};
use App\Outputs\{
    SourceValidationOutput,
    ExtractionOutput,
    TransformationOutput,
    LoadOutput,
    IntegrityOutput,
    ReportOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{FailurePolicy, SuccessCriteria};

final class DataPipelineWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Data Pipeline')
            ->version(1)
            ->contextLoader(PipelineContextLoader::class)

            // Step 1: Validate source configuration
            ->step('validate_source')
                ->name('Validate Source')
                ->job(ValidateSourceJob::class)
                ->produces(SourceValidationOutput::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Step 2: Extract data from source
            ->step('extract_data')
                ->name('Extract Data')
                ->job(ExtractDataJob::class)
                ->requires('validate_source', SourceValidationOutput::class)
                ->produces(ExtractionOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 60)
                ->timeout(1800) // 30 minutes for large extractions
                ->build()

            // Step 3: Transform records in parallel
            ->fanOut('transform_records')
                ->name('Transform Records')
                ->job(TransformRecordJob::class)
                ->items(fn($ctx, $out) => $out->get(ExtractionOutput::class)->records)
                ->successCriteria(SuccessCriteria::Majority) // Allow some failures
                ->parallelism(50) // Process 50 records concurrently
                ->produces(TransformationOutput::class)
                ->failurePolicy(FailurePolicy::ContinueWithPartial)
                ->compensation(CleanupStagingJob::class)
                ->build()

            // Step 4: Load data into destination
            ->step('load_data')
                ->name('Load Data')
                ->job(LoadDataJob::class)
                ->requires('transform_records', TransformationOutput::class)
                ->produces(LoadOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 120)
                ->compensation(CleanupStagingJob::class)
                ->build()

            // Step 5: Verify data integrity (polling)
            ->polling('verify_integrity')
                ->name('Verify Integrity')
                ->job(VerifyIntegrityJob::class)
                ->requires('load_data', LoadOutput::class)
                ->produces(IntegrityOutput::class)
                ->polling(
                    intervalSeconds: 60,
                    maxDurationSeconds: 1800, // 30 minutes max
                    backoffMultiplier: 1.5,
                )
                ->terminationCondition(DataQualityCheckCondition::class)
                ->build()

            // Step 6: Generate report
            ->step('generate_report')
                ->name('Generate Report')
                ->job(GenerateReportJob::class)
                ->requires('verify_integrity', IntegrityOutput::class)
                ->produces(ReportOutput::class)
                ->build()

            // Step 7: Send notification
            ->step('notify')
                ->name('Send Notification')
                ->job(SendNotificationJob::class)
                ->requires('generate_report', ReportOutput::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }

    public function key(): string
    {
        return 'data-pipeline';
    }
}
```

## Context and Outputs

### Pipeline Context

```php
<?php

declare(strict_types=1);

namespace App\Contexts;

use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class PipelineContext implements WorkflowContext
{
    public function __construct(
        public string $pipelineId,
        public string $sourceType,      // 's3', 'api', 'database'
        public array $sourceConfig,
        public string $destinationType,  // 'bigquery', 'snowflake', 'redshift'
        public array $destinationConfig,
        public ?string $transformationRules,
        public ?int $batchSize,
        public ?string $scheduledBy,
    ) {}
}
```

### Context Loader

```php
<?php

declare(strict_types=1);

namespace App\ContextLoaders;

use App\Contexts\PipelineContext;
use App\Models\PipelineRun;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class PipelineContextLoader implements ContextLoader
{
    public function load(WorkflowId $workflowId): WorkflowContext
    {
        $run = PipelineRun::where('workflow_id', $workflowId->value)->firstOrFail();

        return new PipelineContext(
            pipelineId: $run->pipeline_id,
            sourceType: $run->pipeline->source_type,
            sourceConfig: $run->pipeline->source_config,
            destinationType: $run->pipeline->destination_type,
            destinationConfig: $run->pipeline->destination_config,
            transformationRules: $run->pipeline->transformation_rules,
            batchSize: $run->pipeline->batch_size ?? 1000,
            scheduledBy: $run->triggered_by,
        );
    }
}
```

### Outputs

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class SourceValidationOutput implements StepOutput
{
    public function __construct(
        public bool $valid,
        public int $estimatedRecords,
        public ?string $schemaVersion,
    ) {}
}

final readonly class ExtractionOutput implements StepOutput
{
    public function __construct(
        public array $records,       // Record IDs or references
        public int $totalExtracted,
        public string $stagingPath,
    ) {}
}

final readonly class TransformationOutput implements StepOutput, MergeableOutput
{
    public function __construct(
        public array $transformedRecords,
        public int $successCount,
        public int $failureCount,
        public array $errors,
    ) {}

    public function mergeWith(MergeableOutput $other): MergeableOutput
    {
        return new self(
            transformedRecords: [...$this->transformedRecords, ...$other->transformedRecords],
            successCount: $this->successCount + $other->successCount,
            failureCount: $this->failureCount + $other->failureCount,
            errors: [...$this->errors, ...$other->errors],
        );
    }
}

final readonly class LoadOutput implements StepOutput
{
    public function __construct(
        public int $recordsLoaded,
        public string $loadJobId,
        public string $destinationTable,
    ) {}
}

final readonly class IntegrityOutput implements StepOutput
{
    public function __construct(
        public bool $verified,
        public int $sourceCount,
        public int $destinationCount,
        public float $matchPercentage,
    ) {}
}

final readonly class ReportOutput implements StepOutput
{
    public function __construct(
        public string $reportUrl,
        public array $summary,
    ) {}
}
```

## Job Implementations

### Extract Data Job

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Contexts\PipelineContext;
use App\Outputs\{ExtractionOutput, SourceValidationOutput};
use App\Services\DataExtractor;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ExtractDataJob extends OrchestratedJob
{
    public function __construct(
        private readonly DataExtractor $extractor,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(PipelineContext::class);
        $validation = $this->output(SourceValidationOutput::class);

        // Extract data in batches
        $records = [];
        $cursor = null;
        $batchSize = $context->batchSize ?? 1000;

        do {
            $batch = $this->extractor->extract(
                type: $context->sourceType,
                config: $context->sourceConfig,
                batchSize: $batchSize,
                cursor: $cursor,
            );

            $records = [...$records, ...$batch->records];
            $cursor = $batch->nextCursor;

            Log::info('Extracted batch', [
                'count' => count($batch->records),
                'total' => count($records),
            ]);

        } while ($cursor !== null);

        // Store in staging
        $stagingPath = $this->stageRecords($records);

        $this->store(new ExtractionOutput(
            records: array_column($records, 'id'),
            totalExtracted: count($records),
            stagingPath: $stagingPath,
        ));
    }

    private function stageRecords(array $records): string
    {
        $path = "staging/{$this->workflowId()->value}";
        Storage::put("{$path}/records.json", json_encode($records));
        return $path;
    }
}
```

### Transform Record Job (Fan-Out)

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Contexts\PipelineContext;
use App\Outputs\TransformationOutput;
use App\Services\RecordTransformer;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class TransformRecordJob extends OrchestratedJob
{
    public function __construct(
        private readonly string $recordId,
        private readonly int $index,
        private readonly RecordTransformer $transformer,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(PipelineContext::class);

        try {
            // Load record from staging
            $record = $this->loadRecord($this->recordId);

            // Apply transformations
            $transformed = $this->transformer->transform(
                $record,
                $context->transformationRules
            );

            // Validate transformed record
            $this->transformer->validate($transformed, $context->destinationType);

            $this->store(new TransformationOutput(
                transformedRecords: [$transformed],
                successCount: 1,
                failureCount: 0,
                errors: [],
            ));

        } catch (TransformationException $e) {
            $this->store(new TransformationOutput(
                transformedRecords: [],
                successCount: 0,
                failureCount: 1,
                errors: [
                    [
                        'record_id' => $this->recordId,
                        'error' => $e->getMessage(),
                    ],
                ],
            ));
        }
    }

    private function loadRecord(string $recordId): array
    {
        $stagingPath = "staging/{$this->workflowId()->value}/records.json";
        $records = json_decode(Storage::get($stagingPath), true);

        return collect($records)->firstWhere('id', $recordId);
    }
}
```

### Verify Integrity Job (Polling)

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Contexts\PipelineContext;
use App\Outputs\{IntegrityOutput, LoadOutput};
use App\Services\DataVerifier;
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\ValueObjects\{CompletedPollResult, ContinuePollResult, AbortedPollResult};

final class VerifyIntegrityJob extends PollingJob
{
    public function __construct(
        private readonly DataVerifier $verifier,
    ) {}

    protected function poll(): PollResult
    {
        $context = $this->contextAs(PipelineContext::class);
        $loadOutput = $this->output(LoadOutput::class);

        // Check if data is available in destination
        $status = $this->verifier->checkLoadStatus($loadOutput->loadJobId);

        if ($status === 'pending') {
            return new ContinuePollResult(
                message: 'Load job still processing',
            );
        }

        if ($status === 'failed') {
            return new AbortedPollResult(
                reason: 'Load job failed',
            );
        }

        // Verify record counts
        $sourceCount = $loadOutput->recordsLoaded;
        $destinationCount = $this->verifier->countRecords(
            $context->destinationType,
            $context->destinationConfig,
            $loadOutput->destinationTable,
        );

        $matchPercentage = ($destinationCount / $sourceCount) * 100;

        // Accept if 99%+ match
        if ($matchPercentage >= 99) {
            return new CompletedPollResult(
                output: new IntegrityOutput(
                    verified: true,
                    sourceCount: $sourceCount,
                    destinationCount: $destinationCount,
                    matchPercentage: $matchPercentage,
                ),
            );
        }

        // Still replicating or missing data
        if ($matchPercentage >= 90) {
            return new ContinuePollResult(
                message: "Replication at {$matchPercentage}%, waiting for completion",
            );
        }

        // Too many missing records
        return new AbortedPollResult(
            reason: "Only {$matchPercentage}% of records found in destination",
        );
    }
}
```

## Starting the Pipeline

```php
// Start pipeline run
$pipeline = Pipeline::find($pipelineId);

$run = PipelineRun::create([
    'pipeline_id' => $pipeline->id,
    'triggered_by' => auth()->user()?->email,
    'status' => 'pending',
]);

$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('data-pipeline'),
    metadata: [
        'pipeline_run_id' => $run->id,
        'scheduled' => false,
    ],
);

$run->update(['workflow_id' => $workflow->id->value]);
```

## Monitoring

```php
// Track pipeline progress
Event::listen(StepSucceeded::class, function ($event) {
    if ($event->definitionKey->value !== 'data-pipeline') {
        return;
    }

    $run = PipelineRun::where('workflow_id', $event->workflowId->value)->first();

    match ($event->stepKey->value) {
        'extract_data' => $run->update(['extracted_at' => now()]),
        'load_data' => $run->update(['loaded_at' => now()]),
        'verify_integrity' => $run->update(['verified_at' => now()]),
    };
});

Event::listen(WorkflowSucceeded::class, function ($event) {
    if ($event->definitionKey->value !== 'data-pipeline') {
        return;
    }

    $run = PipelineRun::where('workflow_id', $event->workflowId->value)->first();
    $run->update([
        'status' => 'completed',
        'completed_at' => now(),
    ]);
});
```

## Error Recovery

If the pipeline fails partway through:

```bash
# Check pipeline status
php artisan maestro:detail {workflow_id}

# Retry from failed step
php artisan maestro:retry-from-step {workflow_id} load_data

# Or compensate and retry from beginning
php artisan maestro:compensate {workflow_id}
php artisan maestro:retry {workflow_id}
```

## Best Practices

1. **Use staging storage** - Extract to staging before transformation
2. **Enable partial success** - Use `ContinueWithPartial` for fan-out
3. **Implement compensation** - Clean up staging on failure
4. **Poll for completion** - Don't assume immediate consistency
5. **Generate detailed reports** - Track what succeeded and failed
