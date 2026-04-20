# Branching & Conditions

Maestro supports conditional workflow execution through step conditions and branching. This enables dynamic workflows that adapt based on runtime data.

## Step Conditions

Execute steps only when conditions are met:

```php
->step('premium_processing')
    ->job(PremiumProcessingJob::class)
    ->condition(IsPremiumCustomerCondition::class)
    ->build()
```

When the condition evaluates to `false`, the step is skipped with `SkipReason::ConditionFalse`.

### Implementing Conditions

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\ConditionResult;

final readonly class IsPremiumCustomerCondition implements StepCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ConditionResult {
        // Access context
        $customer = $context->customer;

        // Access outputs from previous steps
        $order = $outputs->get(OrderOutput::class);

        // Return result with optional reason
        if ($customer->tier === 'premium') {
            return ConditionResult::pass();
        }

        return ConditionResult::fail(
            reason: "Customer tier is {$customer->tier}, not premium"
        );
    }
}
```

### Condition Based on Output

```php
final readonly class OrderExceedsThresholdCondition implements StepCondition
{
    public function __construct(
        private float $threshold = 1000.00,
    ) {}

    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ConditionResult {
        $order = $outputs->get(OrderValidationOutput::class);

        if ($order->total >= $this->threshold) {
            return ConditionResult::pass();
        }

        return ConditionResult::fail(
            reason: "Order total {$order->total} below threshold {$this->threshold}"
        );
    }
}
```

## Branching

Create diverging workflow paths based on conditions:

```
                    ┌─────────────┐
                    │  Evaluate   │
                    │  Order Type │
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
              ▼            ▼            ▼
         ┌────────┐  ┌──────────┐  ┌─────────┐
         │Digital │  │ Physical │  │ Service │
         │Delivery│  │ Shipping │  │ Schedule│
         └────┬───┘  └────┬─────┘  └────┬────┘
              │           │             │
              └───────────┼─────────────┘
                          │
                          ▼
                    ┌───────────┐
                    │  Confirm  │
                    │   Order   │
                    └───────────┘
```

### Branch Definition

```php
use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Enums\BranchType;
use Maestro\Workflow\ValueObjects\BranchKey;

$builder
    ->step('determine_fulfillment')
        ->job(DetermineFulfillmentJob::class)
        ->branch(new BranchDefinition(
            conditionClass: FulfillmentTypeCondition::class,
            branchType: BranchType::Exclusive,
            branches: [
                'digital' => ['deliver_digital'],
                'physical' => ['reserve_inventory', 'ship_order'],
                'service' => ['schedule_service'],
            ],
            convergenceStepKey: 'confirm_order',
            defaultBranchKey: 'physical',
        ))
        ->build()

    // Digital branch
    ->step('deliver_digital')
        ->job(DeliverDigitalJob::class)
        ->build()

    // Physical branch
    ->step('reserve_inventory')
        ->job(ReserveInventoryJob::class)
        ->build()

    ->step('ship_order')
        ->job(ShipOrderJob::class)
        ->requires('reserve_inventory', InventoryOutput::class)
        ->build()

    // Service branch
    ->step('schedule_service')
        ->job(ScheduleServiceJob::class)
        ->build()

    // Convergence point
    ->step('confirm_order')
        ->job(ConfirmOrderJob::class)
        ->build()
```

### Branch Condition Implementation

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\BranchKey;

final readonly class FulfillmentTypeCondition implements BranchCondition
{
    /**
     * @return BranchKey|BranchKey[]
     */
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): BranchKey|array {
        $order = $outputs->get(OrderOutput::class);

        return match ($order->fulfillmentType) {
            'digital' => BranchKey::fromString('digital'),
            'physical' => BranchKey::fromString('physical'),
            'service' => BranchKey::fromString('service'),
            default => BranchKey::fromString('physical'),
        };
    }
}
```

## Branch Types

### Exclusive Branching

Only one branch executes (XOR logic):

```php
new BranchDefinition(
    branchType: BranchType::Exclusive,
    branches: [
        'approved' => ['process_approved'],
        'rejected' => ['handle_rejection'],
    ],
)
```

The condition must return a single `BranchKey`.

### Inclusive Branching

One or more branches can execute (OR logic):

```php
new BranchDefinition(
    branchType: BranchType::Inclusive,
    branches: [
        'email' => ['send_email'],
        'sms' => ['send_sms'],
        'push' => ['send_push'],
    ],
)
```

The condition can return an array of `BranchKey`:

```php
public function evaluate(...): BranchKey|array
{
    $preferences = $context->customer->notificationPreferences;

    $branches = [];

    if ($preferences->email) {
        $branches[] = BranchKey::fromString('email');
    }
    if ($preferences->sms) {
        $branches[] = BranchKey::fromString('sms');
    }
    if ($preferences->push) {
        $branches[] = BranchKey::fromString('push');
    }

    return $branches ?: [BranchKey::fromString('email')]; // Default
}
```

## Branch Configuration

### Default Branch

Fallback when condition returns no matching branch:

```php
new BranchDefinition(
    conditionClass: OrderTypeCondition::class,
    branchType: BranchType::Exclusive,
    branches: [...],
    defaultBranchKey: 'standard',  // Fallback branch
)
```

### Convergence Point

Where branches rejoin:

```php
new BranchDefinition(
    branches: [
        'fast' => ['express_processing'],
        'standard' => ['standard_processing', 'quality_check'],
    ],
    convergenceStepKey: 'finalize',  // Both branches converge here
)
```

Steps after the convergence point execute regardless of which branch was taken.

## Skipped Steps

Steps not on the active branch are marked as `Skipped`:

```php
// If 'digital' branch is selected:
// - 'deliver_digital' → Executes
// - 'reserve_inventory' → Skipped (SkipReason::NotOnActiveBranch)
// - 'ship_order' → Skipped (SkipReason::NotOnActiveBranch)
// - 'schedule_service' → Skipped (SkipReason::NotOnActiveBranch)
// - 'confirm_order' → Executes (convergence)
```

## Branch Decision Tracking

Branch decisions are recorded for audit:

```php
$decisions = $branchDecisionRepository->findByWorkflowId($workflowId);

foreach ($decisions as $decision) {
    echo "Step {$decision->stepKey}: took branch(es) ";
    echo implode(', ', $decision->selectedBranches);
    echo "\n";
}
```

## Events

Branching dispatches these events:

```php
// When branch condition is evaluated
BranchEvaluated::class
// Properties: workflowId, stepKey, selectedBranches, branchType

// When steps are skipped due to branching
StepSkipped::class
// Properties: workflowId, stepKey, reason (NotOnActiveBranch)
```

## Early Termination

Terminate workflow early based on conditions:

```php
->step('check_eligibility')
    ->job(CheckEligibilityJob::class)
    ->terminationCondition(NotEligibleCondition::class)
    ->build()
```

### Termination Condition

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\TerminationResult;

final readonly class NotEligibleCondition implements TerminationCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): TerminationResult {
        $eligibility = $outputs->get(EligibilityOutput::class);

        if ($eligibility->eligible) {
            return TerminationResult::continue();
        }

        return TerminationResult::terminate(
            reason: "Not eligible: {$eligibility->reason}",
        );
    }
}
```

When terminated:
- Remaining steps marked as `Skipped` with `SkipReason::TerminatedEarly`
- Workflow transitions to `Succeeded` (completed early)
- `WorkflowTerminatedEarly` event dispatched

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\{
    CustomerTierCondition,
    OrderFulfillmentCondition,
    HighValueOrderCondition,
    FraudDetectedCondition,
};
use App\Jobs\Workflow\{
    ValidateOrderJob,
    ProcessRegularOrderJob,
    ProcessPremiumOrderJob,
    ProcessVipOrderJob,
    DigitalDeliveryJob,
    PhysicalShippingJob,
    ServiceSchedulingJob,
    FraudReviewJob,
    ConfirmOrderJob,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\BranchDefinition;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\BranchType;

final class OrderWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing with Branching')
            ->version(1)

            // Validate and check for fraud
            ->step('validate')
                ->job(ValidateOrderJob::class)
                ->produces(OrderValidationOutput::class)
                // Terminate early if fraud detected
                ->terminationCondition(FraudDetectedCondition::class)
                ->build()

            // Branch by customer tier
            ->step('process_by_tier')
                ->job(DetermineProcessingJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->branch(new BranchDefinition(
                    conditionClass: CustomerTierCondition::class,
                    branchType: BranchType::Exclusive,
                    branches: [
                        'regular' => ['process_regular'],
                        'premium' => ['process_premium'],
                        'vip' => ['process_vip', 'assign_concierge'],
                    ],
                    convergenceStepKey: 'fulfill_order',
                    defaultBranchKey: 'regular',
                ))
                ->build()

            // Regular processing
            ->step('process_regular')
                ->job(ProcessRegularOrderJob::class)
                ->build()

            // Premium processing
            ->step('process_premium')
                ->job(ProcessPremiumOrderJob::class)
                ->build()

            // VIP processing with concierge
            ->step('process_vip')
                ->job(ProcessVipOrderJob::class)
                ->build()

            ->step('assign_concierge')
                ->job(AssignConciergeJob::class)
                ->condition(HighValueOrderCondition::class)
                ->build()

            // Convergence: Branch by fulfillment type
            ->step('fulfill_order')
                ->job(DetermineFulfillmentJob::class)
                ->branch(new BranchDefinition(
                    conditionClass: OrderFulfillmentCondition::class,
                    branchType: BranchType::Exclusive,
                    branches: [
                        'digital' => ['deliver_digital'],
                        'physical' => ['ship_physical'],
                        'service' => ['schedule_service'],
                    ],
                    convergenceStepKey: 'confirm',
                ))
                ->build()

            // Fulfillment branches
            ->step('deliver_digital')
                ->job(DigitalDeliveryJob::class)
                ->build()

            ->step('ship_physical')
                ->job(PhysicalShippingJob::class)
                ->build()

            ->step('schedule_service')
                ->job(ServiceSchedulingJob::class)
                ->build()

            // Final confirmation
            ->step('confirm')
                ->job(ConfirmOrderJob::class)
                ->build();
    }
}
```

## Best Practices

### 1. Keep Conditions Simple

Each condition should evaluate one thing:

```php
// Good: Single responsibility
final class IsPremiumCustomerCondition implements StepCondition { ... }
final class OrderExceedsThresholdCondition implements StepCondition { ... }

// Bad: Multiple concerns in one condition
final class IsPremiumAndHighValueCondition implements StepCondition { ... }
```

### 2. Use Default Branches

Always provide a default for robustness:

```php
new BranchDefinition(
    branches: [...],
    defaultBranchKey: 'standard',  // Always have a fallback
)
```

### 3. Document Branch Logic

Use descriptive branch keys and document the logic:

```php
// Clear branch keys
branches: [
    'digital_download' => ['generate_download_link'],
    'physical_shipping' => ['create_shipping_label', 'notify_warehouse'],
    'in_store_pickup' => ['reserve_at_store', 'send_pickup_notification'],
]
```

### 4. Handle All Cases

Ensure conditions handle all possible values:

```php
return match ($order->type) {
    'standard' => BranchKey::fromString('standard'),
    'express' => BranchKey::fromString('express'),
    'overnight' => BranchKey::fromString('overnight'),
    default => BranchKey::fromString('standard'),  // Catch-all
};
```

### 5. Test Branch Paths

Write tests for each branch path:

```php
it('processes digital orders through digital branch', function () {
    $workflow = startWorkflow(OrderWorkflow::class, [
        'order' => Order::factory()->digital()->create(),
    ]);

    processWorkflow($workflow);

    expect($this->wasStepExecuted($workflow, 'deliver_digital'))->toBeTrue();
    expect($this->wasStepSkipped($workflow, 'ship_physical'))->toBeTrue();
});
```

## Next Steps

- [External Triggers](external-triggers.md) - Pause for webhooks and approvals
- [Early Termination](early-termination.md) - Conditional workflow completion
- [Events Reference](../../operations/events.md) - Branch-related events
