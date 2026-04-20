# Testing Workflows

This guide covers strategies for testing Maestro workflows at the unit, integration, and end-to-end levels.

## Testing Philosophy

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Testing Pyramid                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│                              ┌───────┐                                       │
│                             /   E2E   \                                      │
│                            /  Tests    \   ← Few, slow, high confidence     │
│                           /─────────────\                                    │
│                          /  Integration  \                                   │
│                         /     Tests       \ ← Some, medium speed             │
│                        /───────────────────\                                 │
│                       /      Unit Tests     \                                │
│                      /       (Jobs, Logic)   \ ← Many, fast, isolated       │
│                     /─────────────────────────\                              │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Unit Testing Jobs

### Basic Job Test

```php
<?php

declare(strict_types=1);

use App\Jobs\Workflow\ProcessOrderJob;
use App\Outputs\OrderOutput;
use Maestro\Workflow\Testing\JobTestCase;

describe('ProcessOrderJob', function () {
    it('processes order successfully', function () {
        // Arrange
        $context = new OrderContext(
            orderId: 'order-123',
            customerId: 'cust-456',
            amount: 99.99,
        );

        $job = JobTestCase::make(ProcessOrderJob::class)
            ->withContext($context)
            ->create();

        // Act
        $job->execute();

        // Assert
        $output = $job->getOutput(OrderOutput::class);
        expect($output->orderId)->toBe('order-123');
        expect($output->status)->toBe('processed');
    });

    it('throws on invalid order', function () {
        $context = new OrderContext(
            orderId: '',
            customerId: 'cust-456',
            amount: 0,
        );

        $job = JobTestCase::make(ProcessOrderJob::class)
            ->withContext($context)
            ->create();

        expect(fn() => $job->execute())
            ->toThrow(InvalidOrderException::class);
    });
});
```

### Testing with Dependencies

```php
<?php

declare(strict_types=1);

use App\Jobs\Workflow\ProcessPaymentJob;
use App\Services\PaymentGateway;

describe('ProcessPaymentJob', function () {
    it('charges payment through gateway', function () {
        // Mock the payment gateway
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('charge')
            ->once()
            ->with(99.99, 'cust-456')
            ->andReturn(new ChargeResult(
                transactionId: 'txn-789',
                success: true,
            ));

        $job = JobTestCase::make(ProcessPaymentJob::class)
            ->withContext(new OrderContext(
                orderId: 'order-123',
                customerId: 'cust-456',
                amount: 99.99,
            ))
            ->withDependency(PaymentGateway::class, $gateway)
            ->create();

        $job->execute();

        $output = $job->getOutput(PaymentOutput::class);
        expect($output->transactionId)->toBe('txn-789');
    });

    it('throws when payment fails', function () {
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('charge')
            ->once()
            ->andReturn(new ChargeResult(
                transactionId: null,
                success: false,
                error: 'Insufficient funds',
            ));

        $job = JobTestCase::make(ProcessPaymentJob::class)
            ->withContext(new OrderContext(
                orderId: 'order-123',
                customerId: 'cust-456',
                amount: 99.99,
            ))
            ->withDependency(PaymentGateway::class, $gateway)
            ->create();

        expect(fn() => $job->execute())
            ->toThrow(PaymentFailedException::class, 'Insufficient funds');
    });
});
```

### Testing with Previous Outputs

```php
<?php

declare(strict_types=1);

use App\Jobs\Workflow\ShipOrderJob;
use App\Outputs\PaymentOutput;
use App\Outputs\InventoryOutput;

describe('ShipOrderJob', function () {
    it('uses payment and inventory outputs', function () {
        $job = JobTestCase::make(ShipOrderJob::class)
            ->withContext(new OrderContext(orderId: 'order-123'))
            ->withOutput(new PaymentOutput(
                transactionId: 'txn-789',
                amount: 99.99,
            ))
            ->withOutput(new InventoryOutput(
                reserved: true,
                warehouseId: 'wh-1',
            ))
            ->create();

        $job->execute();

        $output = $job->getOutput(ShippingOutput::class);
        expect($output->trackingNumber)->not->toBeNull();
        expect($output->warehouseId)->toBe('wh-1');
    });
});
```

## Integration Testing

### Testing Complete Workflows

```php
<?php

declare(strict_types=1);

use App\Workflows\OrderProcessingWorkflow;
use Maestro\Workflow\Testing\WorkflowTestCase;

describe('OrderProcessingWorkflow', function () {
    uses(WorkflowTestCase::class);

    beforeEach(function () {
        $this->seedTestData();
    });

    it('completes order workflow successfully', function () {
        // Start workflow
        $workflow = $this->startWorkflow(
            OrderProcessingWorkflow::class,
            new OrderContext(
                orderId: 'order-123',
                customerId: 'cust-456',
                amount: 99.99,
            ),
        );

        // Process all steps
        $this->processWorkflow($workflow);

        // Assert final state
        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);

        // Assert all steps completed
        $this->assertStepSucceeded($workflow, 'validate');
        $this->assertStepSucceeded($workflow, 'payment');
        $this->assertStepSucceeded($workflow, 'fulfill');
        $this->assertStepSucceeded($workflow, 'notify');
    });

    it('pauses on payment failure', function () {
        // Mock payment to fail
        $this->mockService(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('charge')->andThrow(
                new PaymentFailedException('Card declined')
            );
        });

        $workflow = $this->startWorkflow(
            OrderProcessingWorkflow::class,
            new OrderContext(
                orderId: 'order-123',
                customerId: 'cust-456',
                amount: 99.99,
            ),
        );

        $this->processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Failed);
        $this->assertStepFailed($workflow, 'payment');
    });
});
```

### Testing Fan-Out Steps

```php
<?php

declare(strict_types=1);

describe('Fan-Out Processing', function () {
    uses(WorkflowTestCase::class);

    it('processes all items in parallel', function () {
        $items = collect(range(1, 10))->map(fn($i) => new Item("item-$i"));

        $workflow = $this->startWorkflow(
            BatchProcessingWorkflow::class,
            new BatchContext(items: $items),
        );

        $this->processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);

        // Verify all items processed
        $stepRun = $this->getStepRun($workflow, 'process_items');
        expect($stepRun->job_records)->toHaveCount(10);
        expect($stepRun->successful_jobs)->toBe(10);
    });

    it('handles partial failures with majority success', function () {
        $items = collect(range(1, 10))->map(fn($i) => new Item("item-$i"));

        // Fail 2 out of 10 items
        $this->mockService(ItemProcessor::class, function ($mock) {
            $mock->shouldReceive('process')
                ->andReturnUsing(function (Item $item) {
                    if (in_array($item->id, ['item-3', 'item-7'])) {
                        throw new ProcessingException("Failed: {$item->id}");
                    }
                    return new ProcessedItem($item->id);
                });
        });

        $workflow = $this->startWorkflow(
            BatchProcessingWorkflow::class,
            new BatchContext(items: $items),
        );

        $this->processWorkflow($workflow);

        // Workflow should succeed with 80% success rate
        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);

        $stepRun = $this->getStepRun($workflow, 'process_items');
        expect($stepRun->successful_jobs)->toBe(8);
        expect($stepRun->failed_jobs)->toBe(2);
    });
});
```

### Testing Polling Steps

```php
<?php

declare(strict_types=1);

describe('Polling Steps', function () {
    uses(WorkflowTestCase::class);

    it('completes when poll returns success', function () {
        $attempts = 0;

        $this->mockService(PaymentChecker::class, function ($mock) use (&$attempts) {
            $mock->shouldReceive('check')
                ->andReturnUsing(function () use (&$attempts) {
                    $attempts++;

                    // Succeed on third attempt
                    if ($attempts >= 3) {
                        return PaymentStatus::Confirmed;
                    }

                    return PaymentStatus::Pending;
                });
        });

        $workflow = $this->startWorkflow(
            PaymentConfirmationWorkflow::class,
            new PaymentContext(transactionId: 'txn-123'),
        );

        // Process with time simulation
        $this->processWorkflowWithPolling($workflow, maxAttempts: 5);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
        expect($attempts)->toBe(3);
    });

    it('times out after max duration', function () {
        $this->mockService(PaymentChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->andReturn(PaymentStatus::Pending);
        });

        $workflow = $this->startWorkflow(
            PaymentConfirmationWorkflow::class,
            new PaymentContext(transactionId: 'txn-123'),
        );

        // Simulate timeout
        $this->processWorkflowWithPolling(
            $workflow,
            maxAttempts: 100,
            simulateTimeout: true,
        );

        $stepRun = $this->getStepRun($workflow, 'wait_for_confirmation');
        expect($stepRun->state)->toBe(StepState::TimedOut);
    });
});
```

### Testing External Triggers

```php
<?php

declare(strict_types=1);

describe('External Triggers', function () {
    uses(WorkflowTestCase::class);

    it('resumes when trigger received', function () {
        $workflow = $this->startWorkflow(
            ApprovalWorkflow::class,
            new ApprovalContext(documentId: 'doc-123'),
        );

        // Process until paused for approval
        $this->processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Paused);
        $this->assertStepAwaitingTrigger($workflow, 'await_approval');

        // Simulate trigger
        $this->triggerWorkflow($workflow, 'approval', [
            'approved' => true,
            'approver' => 'manager@example.com',
        ]);

        // Continue processing
        $this->processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
    });

    it('handles trigger rejection', function () {
        $workflow = $this->startWorkflow(
            ApprovalWorkflow::class,
            new ApprovalContext(documentId: 'doc-123'),
        );

        $this->processWorkflow($workflow);

        // Reject via trigger
        $this->triggerWorkflow($workflow, 'approval', [
            'approved' => false,
            'reason' => 'Insufficient documentation',
        ]);

        $this->processWorkflow($workflow);

        // Should terminate early
        expect($workflow->fresh()->terminated_early)->toBeTrue();
    });
});
```

## End-to-End Testing

### HTTP API Testing

```php
<?php

declare(strict_types=1);

describe('Workflow API', function () {
    uses(RefreshDatabase::class);

    it('starts workflow via API', function () {
        $response = $this->postJson('/api/maestro/workflows', [
            'definition_key' => 'order-processing',
            'metadata' => ['source' => 'api-test'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'definition_key',
                'state',
                'created_at',
            ]);

        expect(WorkflowModel::count())->toBe(1);
    });

    it('receives external trigger', function () {
        // Setup workflow waiting for trigger
        $workflow = $this->createPausedWorkflow('await_approval');

        $response = $this->postJson(
            "/api/maestro/workflows/{$workflow->id}/trigger/approval",
            ['approved' => true],
            ['X-Trigger-Signature' => $this->generateSignature(['approved' => true])],
        );

        $response->assertStatus(200);

        // Process and verify
        $this->artisan('queue:work --once');

        expect($workflow->fresh()->state)->not->toBe(WorkflowState::Paused);
    });
});
```

### Complete Flow Testing

```php
<?php

declare(strict_types=1);

describe('Order Processing E2E', function () {
    uses(RefreshDatabase::class);

    it('processes order from creation to delivery', function () {
        // 1. Create order via API
        $orderResponse = $this->postJson('/api/orders', [
            'customer_id' => 'cust-123',
            'items' => [
                ['product_id' => 'prod-1', 'quantity' => 2],
                ['product_id' => 'prod-2', 'quantity' => 1],
            ],
        ]);

        $orderId = $orderResponse->json('id');

        // 2. Process queue jobs
        $this->artisan('queue:work --stop-when-empty');

        // 3. Check order status
        $statusResponse = $this->getJson("/api/orders/{$orderId}");
        expect($statusResponse->json('status'))->toBe('processing');

        // 4. Simulate external payment confirmation
        $workflow = Order::find($orderId)->workflow;
        $this->postJson(
            "/api/maestro/workflows/{$workflow->id}/trigger/payment-confirmed",
            ['transaction_id' => 'txn-external'],
            ['X-Trigger-Signature' => $this->sign(['transaction_id' => 'txn-external'])],
        );

        // 5. Continue processing
        $this->artisan('queue:work --stop-when-empty');

        // 6. Verify completion
        $finalResponse = $this->getJson("/api/orders/{$orderId}");
        expect($finalResponse->json('status'))->toBe('shipped');

        // 7. Verify workflow state
        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
    });
});
```

## Testing Utilities

### WorkflowTestCase Trait

```php
<?php

declare(strict_types=1);

namespace Maestro\Workflow\Testing;

use Maestro\Workflow\Maestro;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\DefinitionKey;

trait WorkflowTestCase
{
    protected function startWorkflow(
        string $definitionClass,
        object $context = null,
        array $metadata = [],
    ): WorkflowInstance {
        // Register definition
        $definition = new $definitionClass();
        app(WorkflowDefinitionRegistry::class)->register($definition);

        // Set context if provided
        if ($context) {
            $this->setTestContext($definition->key(), $context);
        }

        return Maestro::startWorkflow(
            $definition->key(),
            metadata: $metadata,
        );
    }

    protected function processWorkflow(WorkflowInstance $workflow): void
    {
        $maxIterations = 100;
        $iterations = 0;

        while ($iterations < $maxIterations) {
            $this->artisan('queue:work --once --quiet');

            $workflow->refresh();

            if ($workflow->state->isTerminal() ||
                $workflow->state === WorkflowState::Paused) {
                break;
            }

            $iterations++;
        }
    }

    protected function assertStepSucceeded(
        WorkflowInstance $workflow,
        string $stepKey,
    ): void {
        $stepRun = StepRunModel::where('workflow_id', $workflow->id->value)
            ->where('step_key', $stepKey)
            ->firstOrFail();

        expect($stepRun->state)->toBe(StepState::Succeeded->value);
    }

    protected function assertStepFailed(
        WorkflowInstance $workflow,
        string $stepKey,
    ): void {
        $stepRun = StepRunModel::where('workflow_id', $workflow->id->value)
            ->where('step_key', $stepKey)
            ->firstOrFail();

        expect($stepRun->state)->toBe(StepState::Failed->value);
    }

    protected function triggerWorkflow(
        WorkflowInstance $workflow,
        string $triggerKey,
        array $payload,
    ): void {
        $handler = app(ExternalTriggerHandler::class);

        $handler->handleTrigger(
            $workflow->id,
            StepKey::fromString($triggerKey),
            new TriggerPayload($payload),
        );
    }
}
```

### Fake Repositories

```php
<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class InMemoryWorkflowRepository implements WorkflowRepository
{
    /** @var array<string, WorkflowInstance> */
    private array $workflows = [];

    public function find(WorkflowId $id): ?WorkflowInstance
    {
        return $this->workflows[$id->value] ?? null;
    }

    public function save(WorkflowInstance $workflow): void
    {
        $this->workflows[$workflow->id->value] = $workflow;
    }

    public function all(): array
    {
        return array_values($this->workflows);
    }

    public function clear(): void
    {
        $this->workflows = [];
    }
}
```

## Test Coverage Goals

| Area | Target Coverage |
|------|-----------------|
| Job execute() methods | 100% |
| Workflow definitions | 100% |
| State transitions | 100% |
| Failure policies | 95% |
| Compensation logic | 100% |
| API endpoints | 90% |
| Event handlers | 85% |

## Testing Best Practices

### 1. Test in Isolation

```php
// Good: Each test is independent
it('handles payment failure', function () {
    $workflow = $this->startWorkflow(/* fresh context */);
    // ...
});

// Bad: Tests depend on shared state
$sharedWorkflow = null;

it('creates workflow', function () use (&$sharedWorkflow) {
    $sharedWorkflow = $this->startWorkflow();
});

it('continues workflow', function () use (&$sharedWorkflow) {
    // Depends on previous test
});
```

### 2. Use Factories for Test Data

```php
final class WorkflowFactory
{
    public static function orderWorkflow(array $overrides = []): WorkflowInstance
    {
        return Maestro::startWorkflow(
            DefinitionKey::fromString('order-processing'),
            metadata: array_merge([
                'source' => 'test',
            ], $overrides),
        );
    }
}
```

### 3. Test Edge Cases

```php
describe('Edge Cases', function () {
    it('handles empty fan-out gracefully', function () {
        $workflow = $this->startWorkflow(
            BatchWorkflow::class,
            new BatchContext(items: []), // Empty!
        );

        $this->processWorkflow($workflow);

        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
    });

    it('handles concurrent trigger attempts', function () {
        $workflow = $this->createPausedWorkflow('await_approval');

        // Simulate race condition
        $results = collect([1, 2, 3])->map(fn() =>
            async(fn() => $this->triggerWorkflow($workflow, 'approval', ['approved' => true]))
        );

        // Only one should succeed
        $succeeded = $results->filter(fn($r) => $r->success)->count();
        expect($succeeded)->toBe(1);
    });
});
```

## Next Steps

- [Performance](performance.md) - Performance optimization
- [Monitoring](monitoring.md) - Observability setup
- [Debugging](../troubleshooting/debugging.md) - Debugging techniques
