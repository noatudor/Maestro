# E-commerce Order Processing

This example demonstrates a complete e-commerce order processing workflow with payment processing, inventory management, shipping, and notifications.

## Workflow Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Order Processing Workflow                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌──────────────┐                                                          │
│   │   Validate   │                                                          │
│   │    Order     │                                                          │
│   └──────┬───────┘                                                          │
│          │                                                                   │
│          ▼                                                                   │
│   ┌──────────────┐                                                          │
│   │   Process    │ ◄── Retry 3x, compensation: refund                       │
│   │   Payment    │                                                          │
│   └──────┬───────┘                                                          │
│          │                                                                   │
│          ▼                                                                   │
│   ┌──────────────┐                                                          │
│   │   Reserve    │ ◄── Fan-out per item, compensation: release             │
│   │  Inventory   │                                                          │
│   └──────┬───────┘                                                          │
│          │                                                                   │
│   ┌──────┴──────┐  Branching by fulfillment type                           │
│   │             │                                                           │
│   ▼             ▼                                                           │
│ ┌────────┐  ┌────────┐                                                     │
│ │Digital │  │Physical│                                                     │
│ │Delivery│  │Shipping│                                                     │
│ └───┬────┘  └───┬────┘                                                     │
│     │           │                                                           │
│     └─────┬─────┘                                                           │
│           │                                                                  │
│           ▼                                                                  │
│   ┌──────────────┐                                                          │
│   │    Send      │ ◄── Non-critical, skip on failure                       │
│   │ Confirmation │                                                          │
│   └──────────────┘                                                          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Workflow Definition

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\{FulfillmentTypeCondition, IsDigitalOnlyCondition};
use App\ContextLoaders\OrderContextLoader;
use App\Jobs\Workflow\{
    ValidateOrderJob,
    ProcessPaymentJob,
    ReserveItemInventoryJob,
    DeliverDigitalProductJob,
    CreateShipmentJob,
    SendOrderConfirmationJob,
};
use App\Jobs\Compensation\{
    RefundPaymentJob,
    ReleaseItemInventoryJob,
    CancelShipmentJob,
};
use App\Outputs\{
    OrderValidationOutput,
    PaymentOutput,
    InventoryReservationOutput,
    DigitalDeliveryOutput,
    ShipmentOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\{BranchDefinition, FailureResolutionConfig};
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{
    BranchType,
    CancelBehavior,
    CompensationScope,
    FailurePolicy,
    FailureResolutionStrategy,
    SuccessCriteria,
};

final class OrderProcessingWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Order Processing')
            ->version(1)
            ->contextLoader(OrderContextLoader::class)

            ->failureResolution(
                FailureResolutionConfig::create()
                    ->strategy(FailureResolutionStrategy::AwaitDecision)
                    ->compensationScope(CompensationScope::All)
                    ->cancelBehavior(CancelBehavior::Compensate)
            )

            // Step 1: Validate the order
            ->step('validate')
                ->name('Validate Order')
                ->job(ValidateOrderJob::class)
                ->produces(OrderValidationOutput::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Step 2: Process payment
            ->step('payment')
                ->name('Process Payment')
                ->job(ProcessPaymentJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->produces(PaymentOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30, backoffMultiplier: 2.0)
                ->compensation(RefundPaymentJob::class)
                ->build()

            // Step 3: Reserve inventory (fan-out per item)
            ->fanOut('reserve_inventory')
                ->name('Reserve Inventory')
                ->job(ReserveItemInventoryJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->items(fn($ctx, $out) => $out->get(OrderValidationOutput::class)->items)
                ->jobArguments(fn($item, $index) => [
                    'item' => $item,
                    'sequence' => $index + 1,
                ])
                ->successCriteria(SuccessCriteria::All)
                ->parallelism(5)
                ->produces(InventoryReservationOutput::class)
                ->compensation(ReleaseItemInventoryJob::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Step 4: Branch by fulfillment type
            ->step('route_fulfillment')
                ->name('Route Fulfillment')
                ->job(RouteFulfillmentJob::class)
                ->branch(new BranchDefinition(
                    conditionClass: FulfillmentTypeCondition::class,
                    branchType: BranchType::Exclusive,
                    branches: [
                        'digital' => ['deliver_digital'],
                        'physical' => ['create_shipment'],
                        'mixed' => ['deliver_digital', 'create_shipment'],
                    ],
                    convergenceStepKey: 'confirmation',
                    defaultBranchKey: 'physical',
                ))
                ->build()

            // Branch: Digital delivery
            ->step('deliver_digital')
                ->name('Deliver Digital Products')
                ->job(DeliverDigitalProductJob::class)
                ->requires('validate', OrderValidationOutput::class)
                ->produces(DigitalDeliveryOutput::class)
                ->condition(IsDigitalOnlyCondition::class)
                ->build()

            // Branch: Physical shipping
            ->step('create_shipment')
                ->name('Create Shipment')
                ->job(CreateShipmentJob::class)
                ->requires('reserve_inventory', InventoryReservationOutput::class)
                ->produces(ShipmentOutput::class)
                ->compensation(CancelShipmentJob::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 2)
                ->build()

            // Step 5: Send confirmation (non-critical)
            ->step('confirmation')
                ->name('Send Confirmation')
                ->job(SendOrderConfirmationJob::class)
                ->requires('payment', PaymentOutput::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->onQueue('notifications')
                ->build();
    }
}
```

## Context and Context Loader

```php
<?php

declare(strict_types=1);

namespace App\Contexts;

use App\Models\Order;
use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class OrderContext implements WorkflowContext
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public string $customerEmail,
        public string $customerTier,
        public float $orderTotal,
        public string $currency,
        public array $items,
        public string $shippingAddress,
        public string $billingAddress,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            orderId: $order->id,
            customerId: $order->customer_id,
            customerEmail: $order->customer->email,
            customerTier: $order->customer->tier,
            orderTotal: $order->total,
            currency: $order->currency,
            items: $order->items->toArray(),
            shippingAddress: $order->shipping_address,
            billingAddress: $order->billing_address,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\ContextLoaders;

use App\Contexts\OrderContext;
use App\Models\Order;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class OrderContextLoader implements ContextLoader
{
    public function load(WorkflowId $workflowId): WorkflowContext
    {
        $order = Order::where('workflow_id', $workflowId->value)
            ->with(['customer', 'items'])
            ->firstOrFail();

        return OrderContext::fromOrder($order);
    }
}
```

## Output Classes

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
        public array $items,
        public float $validatedTotal,
        public string $fulfillmentType, // 'digital', 'physical', 'mixed'
        public array $validationMessages = [],
    ) {}
}

final readonly class PaymentOutput implements StepOutput
{
    public function __construct(
        public string $transactionId,
        public string $paymentMethod,
        public float $amount,
        public string $currency,
        public string $status,
        public \DateTimeImmutable $processedAt,
    ) {}
}

final readonly class InventoryReservationOutput implements StepOutput
{
    public function __construct(
        public string $itemId,
        public string $reservationId,
        public int $quantity,
        public string $warehouseId,
        public \DateTimeImmutable $reservedAt,
        public \DateTimeImmutable $expiresAt,
    ) {}
}

final readonly class ShipmentOutput implements StepOutput
{
    public function __construct(
        public string $shipmentId,
        public string $trackingNumber,
        public string $carrier,
        public \DateTimeImmutable $estimatedDelivery,
    ) {}
}

final readonly class DigitalDeliveryOutput implements StepOutput
{
    public function __construct(
        public array $deliveredItems,
        public array $downloadLinks,
        public \DateTimeImmutable $deliveredAt,
    ) {}
}
```

## Job Implementations

### ValidateOrderJob

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Contexts\OrderContext;
use App\Outputs\OrderValidationOutput;
use App\Services\OrderValidator;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ValidateOrderJob extends OrchestratedJob
{
    public function __construct(
        private readonly OrderValidator $validator,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(OrderContext::class);

        $result = $this->validator->validate($context);

        if (!$result->isValid) {
            throw new OrderValidationException($result->errors);
        }

        $this->store(new OrderValidationOutput(
            isValid: true,
            orderId: $context->orderId,
            items: $context->items,
            validatedTotal: $result->validatedTotal,
            fulfillmentType: $result->fulfillmentType,
        ));
    }
}
```

### ProcessPaymentJob

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Contexts\OrderContext;
use App\Outputs\{OrderValidationOutput, PaymentOutput};
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ProcessPaymentJob extends OrchestratedJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(OrderContext::class);
        $validation = $this->output(OrderValidationOutput::class);

        $result = $this->gateway->charge(
            customerId: $context->customerId,
            amount: $validation->validatedTotal,
            currency: $context->currency,
            orderId: $context->orderId,
        );

        if (!$result->success) {
            throw new PaymentFailedException($result->errorMessage);
        }

        $this->store(new PaymentOutput(
            transactionId: $result->transactionId,
            paymentMethod: $result->paymentMethod,
            amount: $result->amount,
            currency: $result->currency,
            status: 'captured',
            processedAt: now()->toImmutable(),
        ));
    }
}
```

### ReserveItemInventoryJob (Fan-out)

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Outputs\InventoryReservationOutput;
use App\Services\InventoryService;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ReserveItemInventoryJob extends OrchestratedJob
{
    public function __construct(
        public readonly array $item,
        public readonly int $sequence,
        private readonly InventoryService $inventory,
    ) {}

    protected function execute(): void
    {
        $reservation = $this->inventory->reserve(
            sku: $this->item['sku'],
            quantity: $this->item['quantity'],
            orderId: $this->contextAs(OrderContext::class)->orderId,
        );

        $this->store(new InventoryReservationOutput(
            itemId: $this->item['id'],
            reservationId: $reservation->id,
            quantity: $this->item['quantity'],
            warehouseId: $reservation->warehouseId,
            reservedAt: now()->toImmutable(),
            expiresAt: now()->addHours(24)->toImmutable(),
        ));
    }
}
```

## Compensation Jobs

### RefundPaymentJob

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Compensation;

use App\Outputs\PaymentOutput;
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class RefundPaymentJob extends OrchestratedJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function execute(): void
    {
        $payment = $this->output(PaymentOutput::class);

        // Idempotency: Check if already refunded
        if ($this->gateway->isRefunded($payment->transactionId)) {
            return;
        }

        $this->gateway->refund(
            transactionId: $payment->transactionId,
            amount: $payment->amount,
            reason: 'Order compensation',
        );

        Log::info('Payment refunded', [
            'workflow_id' => $this->workflowId()->value,
            'transaction_id' => $payment->transactionId,
        ]);
    }
}
```

### ReleaseItemInventoryJob

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Compensation;

use App\Outputs\InventoryReservationOutput;
use App\Services\InventoryService;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class ReleaseItemInventoryJob extends OrchestratedJob
{
    public function __construct(
        public readonly array $item,
        private readonly InventoryService $inventory,
    ) {}

    protected function execute(): void
    {
        // For fan-out compensation, get the specific item's reservation
        $reservations = $this->output(InventoryReservationOutput::class);
        $reservation = collect($reservations)
            ->firstWhere('itemId', $this->item['id']);

        if (!$reservation) {
            return; // Item wasn't reserved
        }

        $this->inventory->release($reservation->reservationId);
    }
}
```

## Conditions

### FulfillmentTypeCondition

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use App\Outputs\OrderValidationOutput;
use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\BranchKey;

final readonly class FulfillmentTypeCondition implements BranchCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): BranchKey {
        $validation = $outputs->get(OrderValidationOutput::class);

        return BranchKey::fromString($validation->fulfillmentType);
    }
}
```

## Starting the Workflow

```php
use App\Models\Order;
use App\Workflows\OrderProcessingWorkflow;
use Maestro\Workflow\Maestro;
use Maestro\Workflow\ValueObjects\DefinitionKey;

// When order is placed
public function placeOrder(PlaceOrderRequest $request): JsonResponse
{
    // Create the order
    $order = Order::create([
        'customer_id' => auth()->id(),
        'items' => $request->items,
        'total' => $request->total,
        // ...
    ]);

    // Start the workflow
    $workflow = Maestro::startWorkflow(
        DefinitionKey::fromString('order-processing'),
    );

    // Link workflow to order
    $order->update(['workflow_id' => $workflow->id->value]);

    return response()->json([
        'order_id' => $order->id,
        'workflow_id' => $workflow->id->value,
        'status' => 'processing',
    ]);
}
```

## Testing

```php
<?php

declare(strict_types=1);

use App\Models\{Order, Customer};
use App\Workflows\OrderProcessingWorkflow;
use Maestro\Workflow\Enums\WorkflowState;

describe('OrderProcessingWorkflow', function () {
    beforeEach(function () {
        $this->customer = Customer::factory()->create();
        $this->order = Order::factory()
            ->for($this->customer)
            ->withItems(3)
            ->create();
    });

    it('processes digital order successfully', function () {
        $this->order->update(['fulfillment_type' => 'digital']);

        $workflow = startWorkflow(OrderProcessingWorkflow::class);
        $this->order->update(['workflow_id' => $workflow->id->value]);

        processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
        expect($this->wasStepExecuted($workflow, 'deliver_digital'))->toBeTrue();
        expect($this->wasStepSkipped($workflow, 'create_shipment'))->toBeTrue();
    });

    it('compensates all steps when shipping fails', function () {
        $this->mockPermanentFailure(CreateShipmentJob::class);

        $workflow = startWorkflow(OrderProcessingWorkflow::class);
        $this->order->update(['workflow_id' => $workflow->id->value]);

        processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Failed);

        // Trigger compensation
        triggerCompensation($workflow);

        expect($this->wasCompensated('payment'))->toBeTrue();
        expect($this->wasCompensated('reserve_inventory'))->toBeTrue();
        expect($this->order->fresh()->status)->toBe('cancelled');
    });

    it('retries payment on transient failure', function () {
        $this->mockFailure(ProcessPaymentJob::class, times: 2);

        $workflow = startWorkflow(OrderProcessingWorkflow::class);
        $this->order->update(['workflow_id' => $workflow->id->value]);

        processWorkflow($workflow);

        expect($this->getStepAttempts($workflow, 'payment'))->toBe(3);
        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
    });
});
```

## Monitoring

```php
// Event listeners for monitoring
Event::listen(WorkflowSucceeded::class, function ($event) {
    $workflow = Workflow::find($event->workflowId);
    $order = Order::where('workflow_id', $event->workflowId->value)->first();

    Metrics::increment('orders.completed');
    Metrics::timing('orders.processing_time', $event->durationSeconds * 1000);

    $order->update(['status' => 'completed']);
});

Event::listen(WorkflowFailed::class, function ($event) {
    $order = Order::where('workflow_id', $event->workflowId->value)->first();

    Alert::send("Order {$order->id} workflow failed");
    $order->update(['status' => 'failed']);
});

Event::listen(CompensationCompleted::class, function ($event) {
    $order = Order::where('workflow_id', $event->workflowId->value)->first();

    $order->update(['status' => 'cancelled']);
    $order->customer->notify(new OrderCancelledNotification($order));
});
```

## Next Steps

- [Document Approval](document-approval.md) - Approval workflow with triggers
- [Data Pipeline](data-pipeline.md) - ETL with polling
- [Payment Processing](payment-processing.md) - Payment with fraud check
