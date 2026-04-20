# Typed Outputs

Maestro provides type-safe data passing between workflow steps through **typed outputs**. This ensures compile-time safety and runtime validation of data dependencies.

## Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     Typed Output Flow                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Step A                          Step B                         │
│   ┌─────────────────┐            ┌─────────────────┐            │
│   │ ValidateJob     │            │ ProcessJob      │            │
│   │                 │            │                 │            │
│   │ produces:       │            │ requires:       │            │
│   │ ValidationOutput│───────────▶│ ValidationOutput│            │
│   │                 │            │                 │            │
│   │ $this->store()  │            │ $this->output() │            │
│   └─────────────────┘            └─────────────────┘            │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Defining Output Classes

Output classes must implement `StepOutput`:

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class OrderValidationOutput implements StepOutput
{
    public function __construct(
        public bool $isValid,
        public string $orderId,
        public array $validatedItems,
        public float $totalAmount,
        public ?string $validationError = null,
    ) {}
}
```

## Producing Output

### Declare in Workflow

```php
->step('validate')
    ->job(ValidateOrderJob::class)
    ->produces(OrderValidationOutput::class)
    ->build()
```

### Store in Job

```php
final class ValidateOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $context = $this->contextAs(OrderContext::class);

        $result = $this->validator->validate($context->order);

        $this->store(new OrderValidationOutput(
            isValid: $result->passed,
            orderId: $context->orderId,
            validatedItems: $result->items,
            totalAmount: $result->total,
            validationError: $result->error,
        ));
    }
}
```

## Requiring Output

### Declare Dependencies

```php
->step('process')
    ->job(ProcessOrderJob::class)
    ->requires('validate', OrderValidationOutput::class)
    ->build()
```

### Access in Job

```php
final class ProcessOrderJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Type-safe access to previous step's output
        $validation = $this->output(OrderValidationOutput::class);

        if (!$validation->isValid) {
            throw new InvalidOrderException($validation->validationError);
        }

        foreach ($validation->validatedItems as $item) {
            $this->processItem($item);
        }
    }
}
```

## Multiple Requirements

Steps can require outputs from multiple previous steps:

```php
->step('finalize')
    ->job(FinalizeOrderJob::class)
    ->requires('validate', OrderValidationOutput::class)
    ->requires('payment', PaymentOutput::class)
    ->requires('shipping', ShippingOutput::class)
    ->build()
```

Access all in job:

```php
protected function execute(): void
{
    $validation = $this->output(OrderValidationOutput::class);
    $payment = $this->output(PaymentOutput::class);
    $shipping = $this->output(ShippingOutput::class);

    // Use all three outputs
}
```

## Optional Outputs

Access output that may not exist:

```php
protected function execute(): void
{
    // Returns null if output doesn't exist
    $optional = $this->outputOrNull(OptionalOutput::class);

    if ($optional !== null) {
        // Use it
    }
}
```

## Fan-Out Output Aggregation

For fan-out steps, outputs from all jobs are aggregated:

### Definition

```php
->fanOut('process_items')
    ->job(ProcessItemJob::class)
    ->items(fn($ctx, $out) => $ctx->items)
    ->produces(ItemResultOutput::class)  // Aggregated
    ->build()
```

### Individual Job

```php
final class ProcessItemJob extends OrchestratedJob
{
    public function __construct(
        public readonly Item $item,
    ) {}

    protected function execute(): void
    {
        $result = $this->process($this->item);

        // Each job stores one output
        $this->store(new ItemResultOutput(
            itemId: $this->item->id,
            status: $result->status,
        ));
    }
}
```

### Downstream Access

```php
final class SummarizeJob extends OrchestratedJob
{
    protected function execute(): void
    {
        // Get aggregated results (collection)
        $results = $this->output(ItemResultOutput::class);

        // It's a collection of all individual outputs
        $successCount = $results->filter(fn($r) => $r->status === 'success')->count();
    }
}
```

## Output from Trigger Payload

External trigger payloads can be stored as outputs:

```php
->step('await_approval')
    ->job(RequestApprovalJob::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'approval',
        payloadOutputClass: ApprovalOutput::class,  // Store payload
    ))
    ->produces(ApprovalOutput::class)
    ->build()
```

The output class receives the trigger payload:

```php
final readonly class ApprovalOutput implements StepOutput
{
    public function __construct(
        public bool $approved,
        public string $approverId,
        public ?string $comments,
    ) {}

    public static function fromPayload(array $data): self
    {
        return new self(
            approved: $data['approved'],
            approverId: $data['approver_id'],
            comments: $data['comments'] ?? null,
        );
    }
}
```

## Serialization

Outputs are JSON-serialized for storage:

```php
// Automatically serialized
$this->store(new MyOutput(
    id: 'abc-123',
    items: ['a', 'b', 'c'],
    metadata: ['key' => 'value'],
));

// Retrieved and deserialized
$output = $this->output(MyOutput::class);
// $output is a fully hydrated MyOutput instance
```

### Custom Serialization

For complex objects, implement custom serialization:

```php
final readonly class ComplexOutput implements StepOutput, \JsonSerializable
{
    public function __construct(
        public Money $amount,
        public DateTimeImmutable $processedAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'amount' => [
                'value' => $this->amount->getAmount(),
                'currency' => $this->amount->getCurrency()->getCode(),
            ],
            'processed_at' => $this->processedAt->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            amount: new Money($data['amount']['value'], new Currency($data['amount']['currency'])),
            processedAt: new DateTimeImmutable($data['processed_at']),
        );
    }
}
```

## Validation

Maestro validates output requirements at runtime:

```php
// Throws MissingRequiredOutputException if 'validate' step
// didn't produce OrderValidationOutput
$output = $this->output(OrderValidationOutput::class);
```

The workflow definition is also validated:

```bash
php artisan maestro:validate

# Checks:
# - All required outputs are produced by earlier steps
# - No circular dependencies
# - Output classes exist and implement StepOutput
```

## Best Practices

### 1. Keep Outputs Focused

Each output should represent one concept:

```php
// Good: Single responsibility
final readonly class PaymentOutput implements StepOutput { ... }
final readonly class ShippingOutput implements StepOutput { ... }

// Bad: Multiple concerns
final readonly class PaymentAndShippingOutput implements StepOutput { ... }
```

### 2. Use Immutable Properties

Make all output properties readonly:

```php
// Good: Immutable
final readonly class MyOutput implements StepOutput
{
    public function __construct(
        public string $id,
        public array $items,
    ) {}
}

// Bad: Mutable
class MyOutput implements StepOutput
{
    public string $id;
    public array $items;
}
```

### 3. Include Timestamps

Track when operations occurred:

```php
final readonly class ProcessingOutput implements StepOutput
{
    public function __construct(
        public string $result,
        public \DateTimeImmutable $processedAt,  // Include timestamp
    ) {}
}
```

### 4. Document Required Context

If an output needs context to be useful, document it:

```php
/**
 * Output from order validation step.
 *
 * Requires OrderContext to be loaded.
 * Used by: process_payment, reserve_inventory steps.
 */
final readonly class OrderValidationOutput implements StepOutput { ... }
```

## Next Steps

- [Step Types](../guide/step-types/overview.md) - Different step configurations
- [Fan-Out Steps](../guide/step-types/fan-out.md) - Parallel processing
- [External Triggers](../guide/advanced/external-triggers.md) - Trigger payloads
