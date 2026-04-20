# Payment Processing Workflow

This example demonstrates a payment processing workflow with retry logic, compensation (refunds), and external webhook integration.

## Workflow Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Payment Processing Workflow                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────┐                                                           │
│   │  validate   │  Validate payment request                                 │
│   │  _payment   │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  authorize  │  Authorize payment (hold funds)                           │
│   │  _payment   │  🔄 Compensation: Void authorization                      │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  fraud      │  Check for fraud signals                                  │
│   │  _check     │  ⚡ Terminates if high risk                               │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  capture    │  Capture the authorized payment                           │
│   │  _payment   │  🔄 Compensation: Refund                                  │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  wait_for   │  Poll/wait for confirmation                               │
│   │  _confirm   │  (Polling or External Trigger)                            │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  update     │  Update order status                                      │
│   │  _order     │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐                                                           │
│   │  notify     │  Send receipt to customer                                 │
│   │  _customer  │                                                           │
│   └─────────────┘                                                           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Workflow Definition

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\HighFraudRiskCondition;
use App\ContextLoaders\PaymentContextLoader;
use App\Jobs\Payment\{
    ValidatePaymentJob,
    AuthorizePaymentJob,
    FraudCheckJob,
    CapturePaymentJob,
    CheckPaymentStatusJob,
    UpdateOrderJob,
    SendReceiptJob,
    VoidAuthorizationJob,
    RefundPaymentJob,
};
use App\Outputs\{
    PaymentValidationOutput,
    AuthorizationOutput,
    FraudCheckOutput,
    CaptureOutput,
    ConfirmationOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\FailurePolicy;

final class PaymentProcessingWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Payment Processing')
            ->version(1)
            ->contextLoader(PaymentContextLoader::class)

            // Step 1: Validate payment details
            ->step('validate_payment')
                ->name('Validate Payment')
                ->job(ValidatePaymentJob::class)
                ->produces(PaymentValidationOutput::class)
                ->failurePolicy(FailurePolicy::FailWorkflow)
                ->build()

            // Step 2: Authorize payment (hold funds)
            ->step('authorize_payment')
                ->name('Authorize Payment')
                ->job(AuthorizePaymentJob::class)
                ->requires('validate_payment', PaymentValidationOutput::class)
                ->produces(AuthorizationOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 5)
                ->compensation(VoidAuthorizationJob::class)
                ->build()

            // Step 3: Fraud check
            ->step('fraud_check')
                ->name('Fraud Check')
                ->job(FraudCheckJob::class)
                ->requires('authorize_payment', AuthorizationOutput::class)
                ->produces(FraudCheckOutput::class)
                ->terminationCondition(HighFraudRiskCondition::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 2, delaySeconds: 10)
                ->build()

            // Step 4: Capture the payment
            ->step('capture_payment')
                ->name('Capture Payment')
                ->job(CapturePaymentJob::class)
                ->requires('fraud_check', FraudCheckOutput::class)
                ->requires('authorize_payment', AuthorizationOutput::class)
                ->produces(CaptureOutput::class)
                ->failurePolicy(FailurePolicy::RetryStep)
                ->retryable(maxAttempts: 3, delaySeconds: 30)
                ->compensation(RefundPaymentJob::class)
                ->build()

            // Step 5: Wait for payment confirmation
            ->polling('wait_for_confirmation')
                ->name('Confirm Payment')
                ->job(CheckPaymentStatusJob::class)
                ->requires('capture_payment', CaptureOutput::class)
                ->produces(ConfirmationOutput::class)
                ->polling(
                    intervalSeconds: 10,
                    maxDurationSeconds: 300, // 5 minute timeout
                    backoffMultiplier: 1.5,
                )
                ->build()

            // Step 6: Update order status
            ->step('update_order')
                ->name('Update Order')
                ->job(UpdateOrderJob::class)
                ->requires('wait_for_confirmation', ConfirmationOutput::class)
                ->build()

            // Step 7: Send receipt (non-critical)
            ->step('notify_customer')
                ->name('Send Receipt')
                ->job(SendReceiptJob::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->build();
    }

    public function key(): string
    {
        return 'payment-processing';
    }
}
```

## Context and Outputs

### Payment Context

```php
<?php

declare(strict_types=1);

namespace App\Contexts;

use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class PaymentContext implements WorkflowContext
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public float $amount,
        public string $currency,
        public string $paymentMethodId,
        public string $paymentMethodType, // 'card', 'bank_transfer', 'wallet'
        public ?string $description,
        public array $metadata,
    ) {}
}
```

### Outputs

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class PaymentValidationOutput implements StepOutput
{
    public function __construct(
        public bool $valid,
        public ?string $paymentMethodFingerprint,
        public ?array $errors,
    ) {}
}

final readonly class AuthorizationOutput implements StepOutput
{
    public function __construct(
        public string $authorizationId,
        public float $authorizedAmount,
        public string $status, // 'authorized', 'declined'
        public ?\DateTimeImmutable $expiresAt,
    ) {}
}

final readonly class FraudCheckOutput implements StepOutput
{
    public function __construct(
        public int $riskScore,       // 0-100
        public string $riskLevel,    // 'low', 'medium', 'high'
        public array $signals,
        public bool $approved,
    ) {}
}

final readonly class CaptureOutput implements StepOutput
{
    public function __construct(
        public string $captureId,
        public string $transactionId,
        public float $capturedAmount,
        public string $status,
    ) {}
}

final readonly class ConfirmationOutput implements StepOutput
{
    public function __construct(
        public bool $confirmed,
        public string $confirmationCode,
        public ?\DateTimeImmutable $confirmedAt,
    ) {}
}
```

## Job Implementations

### Authorize Payment Job

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Contexts\PaymentContext;
use App\Outputs\{AuthorizationOutput, PaymentValidationOutput};
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class AuthorizePaymentJob extends OrchestratedJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(PaymentContext::class);

        // Idempotency check
        $existing = $this->gateway->findAuthorization(
            orderId: $context->orderId,
        );

        if ($existing) {
            $this->store(new AuthorizationOutput(
                authorizationId: $existing->id,
                authorizedAmount: $existing->amount,
                status: $existing->status,
                expiresAt: $existing->expiresAt,
            ));
            return;
        }

        // Create new authorization
        $result = $this->gateway->authorize(
            amount: $context->amount,
            currency: $context->currency,
            paymentMethodId: $context->paymentMethodId,
            metadata: [
                'order_id' => $context->orderId,
                'workflow_id' => $this->workflowId()->value,
            ],
        );

        if ($result->status === 'declined') {
            throw new PaymentDeclinedException(
                "Payment declined: {$result->declineReason}"
            );
        }

        $this->store(new AuthorizationOutput(
            authorizationId: $result->id,
            authorizedAmount: $result->amount,
            status: $result->status,
            expiresAt: $result->expiresAt,
        ));
    }
}
```

### Void Authorization Job (Compensation)

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Outputs\AuthorizationOutput;
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class VoidAuthorizationJob extends OrchestratedJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function execute(): void
    {
        $authorization = $this->outputOrNull(AuthorizationOutput::class);

        if (!$authorization) {
            // No authorization to void
            return;
        }

        // Idempotency check
        $status = $this->gateway->getAuthorizationStatus($authorization->authorizationId);

        if ($status === 'voided' || $status === 'expired') {
            // Already voided or expired
            return;
        }

        if ($status === 'captured') {
            // Cannot void captured payment - needs refund
            Log::warning('Cannot void captured authorization', [
                'authorization_id' => $authorization->authorizationId,
            ]);
            return;
        }

        $this->gateway->voidAuthorization($authorization->authorizationId);

        Log::info('Authorization voided', [
            'authorization_id' => $authorization->authorizationId,
            'workflow_id' => $this->workflowId()->value,
        ]);
    }
}
```

### Fraud Check Job

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Contexts\PaymentContext;
use App\Outputs\{AuthorizationOutput, FraudCheckOutput};
use App\Services\FraudDetectionService;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class FraudCheckJob extends OrchestratedJob
{
    public function __construct(
        private readonly FraudDetectionService $fraudService,
    ) {}

    protected function execute(): void
    {
        $context = $this->contextAs(PaymentContext::class);
        $authorization = $this->output(AuthorizationOutput::class);

        $result = $this->fraudService->check(
            customerId: $context->customerId,
            amount: $context->amount,
            paymentMethodId: $context->paymentMethodId,
            authorizationId: $authorization->authorizationId,
        );

        $this->store(new FraudCheckOutput(
            riskScore: $result->score,
            riskLevel: $result->level,
            signals: $result->signals,
            approved: $result->score < 80, // Auto-reject if score >= 80
        ));
    }
}
```

### High Fraud Risk Termination Condition

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use App\Outputs\FraudCheckOutput;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\TerminationResult;

final readonly class HighFraudRiskCondition implements TerminationCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): TerminationResult {
        $fraudCheck = $outputs->get(FraudCheckOutput::class);

        if ($fraudCheck->approved) {
            return TerminationResult::continue();
        }

        return TerminationResult::terminate(
            reason: "High fraud risk detected (score: {$fraudCheck->riskScore}). " .
                   "Signals: " . implode(', ', $fraudCheck->signals),
        );
    }
}
```

### Check Payment Status Job (Polling)

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Outputs\{CaptureOutput, ConfirmationOutput};
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\ValueObjects\{CompletedPollResult, ContinuePollResult, AbortedPollResult};

final class CheckPaymentStatusJob extends PollingJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function poll(): PollResult
    {
        $capture = $this->output(CaptureOutput::class);

        $status = $this->gateway->getTransactionStatus($capture->transactionId);

        return match ($status->state) {
            'confirmed' => new CompletedPollResult(
                output: new ConfirmationOutput(
                    confirmed: true,
                    confirmationCode: $status->confirmationCode,
                    confirmedAt: $status->confirmedAt,
                ),
            ),

            'pending' => new ContinuePollResult(
                message: 'Payment still processing',
            ),

            'failed' => new AbortedPollResult(
                reason: "Payment failed: {$status->failureReason}",
            ),
        };
    }
}
```

### Refund Payment Job (Compensation)

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Outputs\CaptureOutput;
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class RefundPaymentJob extends OrchestratedJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function execute(): void
    {
        $capture = $this->outputOrNull(CaptureOutput::class);

        if (!$capture) {
            // No capture to refund
            return;
        }

        // Idempotency check
        $existing = $this->gateway->findRefund(
            transactionId: $capture->transactionId,
        );

        if ($existing) {
            Log::info('Refund already processed', [
                'refund_id' => $existing->id,
            ]);
            return;
        }

        $refund = $this->gateway->refund(
            transactionId: $capture->transactionId,
            amount: $capture->capturedAmount,
            reason: 'Workflow compensation',
        );

        Log::info('Payment refunded', [
            'refund_id' => $refund->id,
            'transaction_id' => $capture->transactionId,
            'amount' => $capture->capturedAmount,
        ]);
    }
}
```

## Webhook Integration

For payment processors that confirm via webhook:

### Using External Triggers Instead of Polling

```php
// In workflow definition, replace polling with pause trigger
->step('wait_for_confirmation')
    ->name('Wait for Confirmation')
    ->job(RequestPaymentConfirmationJob::class)
    ->requires('capture_payment', CaptureOutput::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'payment-confirmation',
        payloadOutputClass: ConfirmationOutput::class,
        timeoutSeconds: 3600, // 1 hour
        timeoutPolicy: TriggerTimeoutPolicy::FailStep,
    ))
    ->build()
```

### Webhook Handler

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Outputs\ConfirmationOutput;
use Maestro\Workflow\Application\Orchestration\ExternalTriggerHandler;
use Maestro\Workflow\ValueObjects\{StepKey, TriggerPayload, WorkflowId};

final class StripeWebhookController
{
    public function __construct(
        private readonly ExternalTriggerHandler $triggerHandler,
    ) {}

    public function handle(Request $request)
    {
        // Verify Stripe signature...
        $event = \Stripe\Webhook::constructEvent(
            $request->getContent(),
            $request->header('Stripe-Signature'),
            config('services.stripe.webhook_secret'),
        );

        if ($event->type === 'payment_intent.succeeded') {
            $this->handlePaymentSucceeded($event->data->object);
        }

        return response()->json(['received' => true]);
    }

    private function handlePaymentSucceeded($paymentIntent): void
    {
        // Find the workflow waiting for this payment
        $transaction = PaymentTransaction::where(
            'stripe_payment_intent_id',
            $paymentIntent->id
        )->first();

        if (!$transaction?->workflow_id) {
            return;
        }

        $this->triggerHandler->handleTrigger(
            WorkflowId::fromString($transaction->workflow_id),
            StepKey::fromString('wait_for_confirmation'),
            new TriggerPayload([
                'confirmed' => true,
                'confirmation_code' => $paymentIntent->id,
                'confirmed_at' => now()->toIso8601String(),
            ]),
        );
    }
}
```

## Starting a Payment

```php
$order = Order::find($orderId);

$workflow = Maestro::startWorkflow(
    DefinitionKey::fromString('payment-processing'),
    metadata: [
        'order_id' => $order->id,
        'source' => 'checkout',
    ],
);

$order->update([
    'payment_workflow_id' => $workflow->id->value,
    'payment_status' => 'processing',
]);
```

## Handling Failures

```bash
# If payment fails, compensate to refund/void
php artisan maestro:compensate {workflow_id}

# Retry failed payment
php artisan maestro:retry {workflow_id}

# Or retry from specific step
php artisan maestro:retry-from-step {workflow_id} capture_payment
```

## Monitoring

```php
Event::listen(WorkflowFailed::class, function ($event) {
    if ($event->definitionKey->value !== 'payment-processing') {
        return;
    }

    $order = Order::where('payment_workflow_id', $event->workflowId->value)->first();

    // Alert on-call team for failed payments
    Alert::payment('Payment failed', [
        'order_id' => $order->id,
        'workflow_id' => $event->workflowId->value,
        'reason' => $event->reason,
    ]);
});
```

## Best Practices

1. **Always use idempotency** - Payment operations must be idempotent
2. **Implement compensation** - Every charge must have a refund path
3. **Use fraud checks** - Terminate early for high-risk transactions
4. **Log extensively** - Payment flows need detailed audit trails
5. **Handle timeouts** - Payments can take time to confirm
