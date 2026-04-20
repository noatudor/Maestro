# Examples Overview

This section provides complete, working examples of Maestro workflows for common use cases. Each example includes:

- Complete workflow definition
- All job implementations
- Output classes
- Conditions and configuration
- Testing patterns

## Example Workflows

| Example | Description | Features Demonstrated |
|---------|-------------|----------------------|
| [E-commerce Order](order-processing.md) | Order processing with payment, inventory, and shipping | Fan-out, compensation, branching |
| [Document Approval](document-approval.md) | Multi-level approval workflow | External triggers, timeouts, conditions |
| [Data Pipeline](data-pipeline.md) | ETL data processing pipeline | Polling, fan-out, error handling |
| [Payment Processing](payment-processing.md) | Payment with fraud check and retry | Auto-retry, compensation, branching |

## Quick Start Template

Use this template as a starting point for new workflows:

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Jobs\Workflow\{FirstJob, SecondJob, ThirdJob};
use App\Outputs\{FirstOutput, SecondOutput};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\FailurePolicy;

final class MyWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('My Workflow')
            ->version(1)
            ->contextLoader(MyContextLoader::class)

            // Configure failure handling
            ->failureResolution(FailureResolutionConfig::autoRetry())

            // Step 1: Initial processing
            ->step('first_step')
                ->name('First Step')
                ->job(FirstJob::class)
                ->produces(FirstOutput::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Step 2: Depends on first step
            ->step('second_step')
                ->name('Second Step')
                ->job(SecondJob::class)
                ->requires('first_step', FirstOutput::class)
                ->produces(SecondOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30)
                ->build()

            // Step 3: Final step
            ->step('third_step')
                ->name('Third Step')
                ->job(ThirdJob::class)
                ->requires('second_step', SecondOutput::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }
}
```

## Job Template

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Outputs\MyOutput;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class MyJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Access workflow context
        $context = $this->contextAs(MyContext::class);

        // Access outputs from previous steps
        $previousOutput = $this->output(PreviousOutput::class);

        // Perform business logic
        $result = $this->doSomething($context, $previousOutput);

        // Store output for downstream steps
        $this->store(new MyOutput(
            value: $result->value,
            processedAt: now(),
        ));
    }

    private function doSomething(MyContext $context, PreviousOutput $output): Result
    {
        // Your business logic here
    }
}
```

## Output Template

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class MyOutput implements StepOutput
{
    public function __construct(
        public string $value,
        public \DateTimeImmutable $processedAt,
    ) {}
}
```

## Context Template

```php
<?php

declare(strict_types=1);

namespace App\Contexts;

use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class MyContext implements WorkflowContext
{
    public function __construct(
        public string $entityId,
        public string $userId,
        public array $metadata,
    ) {}
}
```

## Context Loader Template

```php
<?php

declare(strict_types=1);

namespace App\ContextLoaders;

use App\Contexts\MyContext;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class MyContextLoader implements ContextLoader
{
    public function __construct(
        private MyRepository $repository,
    ) {}

    public function load(WorkflowId $workflowId): WorkflowContext
    {
        $entity = $this->repository->findByWorkflowId($workflowId);

        return new MyContext(
            entityId: $entity->id,
            userId: $entity->user_id,
            metadata: $entity->metadata,
        );
    }
}
```

## Testing Template

```php
<?php

declare(strict_types=1);

use App\Workflows\MyWorkflow;
use App\Outputs\{FirstOutput, SecondOutput};
use Maestro\Workflow\Enums\WorkflowState;

describe('MyWorkflow', function () {
    beforeEach(function () {
        $this->workflow = startWorkflow(MyWorkflow::class, [
            'entity_id' => 'test-123',
        ]);
    });

    it('completes successfully with valid data', function () {
        processWorkflow($this->workflow);

        expect($this->workflow->fresh()->state)
            ->toBe(WorkflowState::Succeeded);

        expect($this->getOutput(FirstOutput::class))
            ->not->toBeNull();
    });

    it('handles failure in second step with retry', function () {
        // Mock failure
        $this->mockFailure(SecondJob::class, times: 2);

        processWorkflow($this->workflow);

        expect($this->getStepAttempts('second_step'))
            ->toBe(3);  // 2 failures + 1 success

        expect($this->workflow->fresh()->state)
            ->toBe(WorkflowState::Succeeded);
    });

    it('compensates on unrecoverable failure', function () {
        $this->mockPermanentFailure(SecondJob::class);

        processWorkflow($this->workflow);
        triggerCompensation($this->workflow);

        expect($this->wasCompensated('first_step'))
            ->toBeTrue();
    });
});
```

## Common Patterns

### Pattern: Conditional Processing

```php
->step('premium_processing')
    ->job(PremiumProcessingJob::class)
    ->condition(IsPremiumCustomerCondition::class)
    ->build()
```

### Pattern: Fan-Out with Partial Success

```php
->fanOut('notify_all')
    ->job(NotifyUserJob::class)
    ->items(fn($ctx, $out) => $ctx->users)
    ->successCriteria(SuccessCriteria::Majority)
    ->failurePolicy(FailurePolicy::ContinueWithPartial)
    ->build()
```

### Pattern: External Approval

```php
->step('await_approval')
    ->job(RequestApprovalJob::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'manager-approval',
        timeoutSeconds: 259200,
        timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
        resumeConditionClass: ApprovalCondition::class,
    ))
    ->build()
```

### Pattern: Polling for External Status

```php
->polling('check_status')
    ->job(CheckStatusJob::class)
    ->polling(
        intervalSeconds: 30,
        maxDurationSeconds: 3600,
        backoffMultiplier: 1.5,
        timeoutPolicy: PollTimeoutPolicy::PauseWorkflow,
    )
    ->build()
```

### Pattern: Branching by Type

```php
->step('route_order')
    ->job(RouteOrderJob::class)
    ->branch(new BranchDefinition(
        conditionClass: OrderTypeCondition::class,
        branchType: BranchType::Exclusive,
        branches: [
            'digital' => ['deliver_digital'],
            'physical' => ['ship_physical'],
        ],
        convergenceStepKey: 'confirm',
    ))
    ->build()
```

### Pattern: Compensation Chain

```php
->step('charge_card')
    ->job(ChargeCardJob::class)
    ->compensation(RefundCardJob::class)
    ->build()

->step('reserve_inventory')
    ->job(ReserveInventoryJob::class)
    ->compensation(ReleaseInventoryJob::class)
    ->build()

->step('create_shipment')
    ->job(CreateShipmentJob::class)
    ->compensation(CancelShipmentJob::class)
    ->build()
```

## Next Steps

- [E-commerce Order](order-processing.md) - Complete order processing example
- [Document Approval](document-approval.md) - Approval workflow example
- [Data Pipeline](data-pipeline.md) - ETL pipeline example
