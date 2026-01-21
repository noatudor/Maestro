# Conditional Steps in Maestro Workflows

This document demonstrates how to use conditional steps to create dynamic workflows that can skip or execute steps based on runtime conditions.

## Overview

Maestro workflows now support conditional step execution, allowing workflows to dynamically adapt their behavior based on:
- Workflow state  
- Available outputs from previous steps
- Custom business logic

Steps with conditions that evaluate to `false` are automatically skipped, and the workflow advances to the next executable step.

## Basic Usage

### Using Closure Conditions

Add conditional logic using `ClosureCondition`:

```php
use Maestro\Workflow\Definition\Builders\SingleJobStepBuilder;
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Conditions\ClosureCondition;

$workflow = WorkflowDefinitionBuilder::create('order-processing', '1.0.0')
    ->step(
        SingleJobStepBuilder::create('validate-order')
            ->job(ValidateOrderJob::class)
            ->produces(OrderValidationOutput::class)
            ->build()
    )
    ->step(
        SingleJobStepBuilder::create('apply-discount')
            ->job(ApplyDiscountJob::class)
            ->requires(OrderValidationOutput::class)
            ->when(ClosureCondition::create(
                fn ($workflow, $context) => $context->get(OrderValidationOutput::class)->isEligibleForDiscount()
            ))
            ->build()
    )
    ->step(
        SingleJobStepBuilder::create('charge-payment')
            ->job(ChargePaymentJob::class)
            ->requires(OrderValidationOutput::class)
            ->build()
    )
    ->build();
```

In this example:
- The `apply-discount` step only executes if `isEligibleForDiscount()` returns `true`
- If the condition is false, the step is skipped
- The workflow proceeds directly to `charge-payment`

### Using Output Existence Conditions

Check if specific outputs exist before executing a step:

```php
use Maestro\Workflow\Definition\Conditions\OutputExistsCondition;

$workflow = WorkflowDefinitionBuilder::create('data-pipeline', '1.0.0')
    ->step(
        SingleJobStepBuilder::create('fetch-data')
            ->job(FetchDataJob::class)
            ->produces(DataOutput::class)
            ->build()
    )
    ->step(
        SingleJobStepBuilder::create('enrich-data')
            ->job(EnrichDataJob::class)
            ->produces(EnrichedDataOutput::class)
            ->build()
    )
    ->step(
        SingleJobStepBuilder::create('analyze-enriched')
            ->job(AnalyzeEnrichedJob::class)
            ->when(OutputExistsCondition::create(EnrichedDataOutput::class))
            ->build()
    )
    ->build();
```

### Custom Condition Classes

Create reusable condition classes for complex business logic:

```php
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Domain\WorkflowInstance;

final readonly class BusinessHoursCondition implements StepCondition
{
    public function shouldExecute(WorkflowInstance $workflowInstance, WorkflowContext $context): bool
    {
        $now = now();
        return $now->isWeekday() && $now->hour >= 9 && $now->hour < 17;
    }
}

// Usage
$step = SingleJobStepBuilder::create('send-notifications')
    ->job(SendNotificationsJob::class)
    ->when(new BusinessHoursCondition())
    ->build();
```

## How It Works

1. **Condition Evaluation**: When a workflow advances to the next step, the `WorkflowAdvancer` evaluates the step's condition (if present)

2. **Automatic Skipping**: If a condition returns `false`, the step is automatically skipped, and the workflow advances to the next step

3. **Sequential Processing**: The workflow continues evaluating subsequent steps until it finds one with a `true` condition or reaches the end

4. **Context Access**: Conditions have access to:
   - The current `WorkflowInstance` (for workflow state)
   - The `WorkflowContext` (for accessing outputs from previous steps)

## Built-in Conditions

Maestro provides three condition implementations:

### AlwaysCondition
Always returns `true`. Used as default when no condition is specified.

```php
use Maestro\Workflow\Definition\Conditions\AlwaysCondition;

$step = SingleJobStepBuilder::create('always-run')
    ->job(MyJob::class)
    ->when(new AlwaysCondition())
    ->build();
```

### ClosureCondition
Evaluates a custom closure with full access to workflow instance and context.

```php
use Maestro\Workflow\Definition\Conditions\ClosureCondition;

$step = SingleJobStepBuilder::create('conditional-step')
    ->job(MyJob::class)
    ->when(ClosureCondition::create(
        fn (WorkflowInstance $workflow, WorkflowContext $context): bool => 
            $workflow->definitionKey->toString() === 'special-workflow'
    ))
    ->build();
```

### OutputExistsCondition
Checks if a specific output class is available in the context.

```php
use Maestro\Workflow\Definition\Conditions\OutputExistsCondition;

$step = SingleJobStepBuilder::create('requires-output')
    ->job(MyJob::class)
    ->when(OutputExistsCondition::create(SomeOutput::class))
    ->build();
```

## Best Practices

1. **Keep Conditions Simple**: Conditions should be fast to evaluate. Avoid expensive operations like database queries.

2. **Deterministic Logic**: Conditions should produce consistent results for the same inputs. Avoid time-based logic unless necessary.

3. **Document Dependencies**: Clearly document which outputs a conditional step depends on.

4. **Test Thoroughly**: Test both execution paths (condition true and false) in your workflow tests.

5. **Avoid Side Effects**: Conditions should only read data, never modify state.

## Migration from Static Workflows

Existing workflows without conditions continue to work unchanged. All steps without conditions are treated as if they have an `AlwaysCondition` (always execute).

This ensures complete backward compatibility with existing workflow definitions.

## Advanced Example: Multiple Conditional Branches

```php
$workflow = WorkflowDefinitionBuilder::create('customer-onboarding', '1.0.0')
    ->step(
        SingleJobStepBuilder::create('validate-customer')
            ->job(ValidateCustomerJob::class)
            ->produces(CustomerValidationOutput::class)
            ->build()
    )
    // Only execute KYC for high-risk customers
    ->step(
        SingleJobStepBuilder::create('kyc-verification')
            ->job(KYCVerificationJob::class)
            ->when(ClosureCondition::create(
                fn ($wf, $ctx) => $ctx->get(CustomerValidationOutput::class)->requiresKYC()
            ))
            ->produces(KYCOutput::class)
            ->build()
    )
    // Only execute manual review when flagged
    ->step(
        SingleJobStepBuilder::create('manual-review')
            ->job(ManualReviewJob::class)
            ->when(ClosureCondition::create(
                fn ($wf, $ctx) => $ctx->get(CustomerValidationOutput::class)->needsManualReview()
            ))
            ->produces(ReviewOutput::class)
            ->build()
    )
    // Always activate account at the end
    ->step(
        SingleJobStepBuilder::create('activate-account')
            ->job(ActivateAccountJob::class)
            ->build()
    )
    ->build();
```

In this workflow:
- `kyc-verification` only runs for high-risk customers
- `manual-review` only runs when flagged for review
- `activate-account` always runs (no condition)

## Implementation Details

The conditional step system is implemented through:

1. **`StepCondition` Interface**: Defines the contract for all conditions
2. **`StepConditionEvaluator` Service**: Evaluates conditions at runtime
3. **`WorkflowAdvancer` Changes**: Modified to skip steps with false conditions
4. **Builder Support**: `when()` method available on all step builders

All changes are backward compatible and require no modifications to existing workflows.
