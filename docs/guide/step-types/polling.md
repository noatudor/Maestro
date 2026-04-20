# Polling Steps

Polling steps repeatedly execute a job until a condition is met or a timeout occurs. They are designed for monitoring external systems, waiting for approvals, or checking long-running processes.

## Basic Configuration

```php
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;

$builder
    ->polling('wait_for_payment')
        ->name('Wait for Payment Confirmation')
        ->job(CheckPaymentStatusJob::class)
        ->polling(
            intervalSeconds: 30,
            maxDurationSeconds: 3600,
        )
        ->produces(PaymentConfirmationOutput::class)
        ->build();
```

## Polling Configuration

### Core Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `intervalSeconds` | Base interval between polls | Required |
| `maxDurationSeconds` | Total time before timeout | Required |
| `maxAttempts` | Maximum poll attempts | null (unlimited) |

```php
->polling(
    intervalSeconds: 60,        // Poll every 60 seconds
    maxDurationSeconds: 86400,  // Max 24 hours total
    maxAttempts: 100,           // Stop after 100 attempts
)
```

### Exponential Backoff

Increase intervals over time to reduce load:

```php
->polling(
    intervalSeconds: 10,         // Start at 10 seconds
    maxDurationSeconds: 3600,
    backoffMultiplier: 1.5,      // Increase by 50% each poll
    maxIntervalSeconds: 300,     // Cap at 5 minutes
)
```

Interval progression: 10s → 15s → 22s → 33s → 50s → 75s → 112s → 168s → 252s → 300s (capped)

## Poll Result Types

Polling jobs return a `PollResult` to indicate the outcome:

### CompletedPollResult

Polling finished successfully:

```php
use Maestro\Workflow\ValueObjects\CompletedPollResult;

final class CheckPaymentStatusJob extends OrchestratedJob
{
    protected function execute(): PollResult
    {
        $status = $this->paymentService->checkStatus($this->transactionId);

        if ($status->isConfirmed()) {
            return new CompletedPollResult(
                output: new PaymentConfirmationOutput(
                    transactionId: $this->transactionId,
                    confirmedAt: $status->confirmedAt,
                    amount: $status->amount,
                )
            );
        }

        return new ContinuePollResult();
    }
}
```

### ContinuePollResult

Continue polling with next scheduled attempt:

```php
use Maestro\Workflow\ValueObjects\ContinuePollResult;

if ($status->isPending()) {
    return new ContinuePollResult(
        message: "Payment still pending, last check: {$status->checkedAt}",
    );
}
```

### AbortedPollResult

Stop polling due to unrecoverable condition:

```php
use Maestro\Workflow\ValueObjects\AbortedPollResult;

if ($status->isRejected()) {
    return new AbortedPollResult(
        reason: "Payment rejected: {$status->rejectionReason}",
    );
}
```

## Timeout Policies

Configure behavior when polling times out:

### Fail Workflow (Default)

```php
use Maestro\Workflow\Enums\PollTimeoutPolicy;

->polling(
    intervalSeconds: 30,
    maxDurationSeconds: 3600,
    timeoutPolicy: PollTimeoutPolicy::FailWorkflow,
)
```

### Pause Workflow

```php
->polling(
    intervalSeconds: 30,
    maxDurationSeconds: 3600,
    timeoutPolicy: PollTimeoutPolicy::PauseWorkflow,
)
```

Workflow pauses for manual intervention. Resume via API or console.

### Continue with Default

```php
->polling(
    intervalSeconds: 30,
    maxDurationSeconds: 3600,
    timeoutPolicy: PollTimeoutPolicy::ContinueWithDefault,
    defaultOutputClass: DefaultPaymentOutput::class,
)
```

Uses a default output value and continues to next step.

## Accessing Previous Attempts

Jobs can access history of previous poll attempts:

```php
final class CheckApprovalJob extends OrchestratedJob
{
    protected function execute(): PollResult
    {
        // Access previous attempts
        $previousAttempts = $this->pollAttempts();

        $attemptCount = $previousAttempts->count();
        $lastAttempt = $previousAttempts->last();

        // Adjust behavior based on history
        if ($attemptCount > 10) {
            logger()->warning('Approval taking longer than expected', [
                'workflowId' => $this->workflowId(),
                'attempts' => $attemptCount,
            ]);
        }

        return $this->checkStatus();
    }
}
```

## Complete Job Example

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Outputs\PaymentConfirmationOutput;
use App\Services\PaymentGateway;
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Contracts\PollResult;
use Maestro\Workflow\ValueObjects\{
    AbortedPollResult,
    CompletedPollResult,
    ContinuePollResult,
};

final class CheckPaymentStatusJob extends PollingJob
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    protected function poll(): PollResult
    {
        $context = $this->contextAs(PaymentContext::class);
        $status = $this->gateway->checkStatus($context->transactionId);

        return match ($status->state) {
            'confirmed' => new CompletedPollResult(
                output: new PaymentConfirmationOutput(
                    transactionId: $context->transactionId,
                    confirmedAt: $status->confirmedAt,
                    amount: $status->amount,
                ),
            ),

            'pending', 'processing' => new ContinuePollResult(
                message: "Status: {$status->state}, last update: {$status->updatedAt}",
            ),

            'rejected', 'failed' => new AbortedPollResult(
                reason: "Payment {$status->state}: {$status->errorMessage}",
            ),

            default => new ContinuePollResult(),
        };
    }
}
```

## Resume Conditions

Configure conditions for accepting external triggers during polling:

```php
->polling('wait_for_approval')
    ->job(CheckApprovalJob::class)
    ->polling(
        intervalSeconds: 60,
        maxDurationSeconds: 86400,
    )
    ->resumeCondition(ApprovalResumeCondition::class)
    ->build()
```

Implement the condition:

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\ResumeConditionResult;

final readonly class ApprovalResumeCondition implements ResumeCondition
{
    public function evaluate(TriggerPayload $payload): ResumeConditionResult
    {
        if (!isset($payload->data['approved'])) {
            return ResumeConditionResult::reject('Missing approval status');
        }

        if ($payload->data['approved'] === true) {
            return ResumeConditionResult::accept();
        }

        return ResumeConditionResult::reject('Approval denied');
    }
}
```

## Combining with External Triggers

Polling steps can be interrupted by external triggers:

```php
->polling('wait_for_document')
    ->job(CheckDocumentStatusJob::class)
    ->polling(
        intervalSeconds: 300,
        maxDurationSeconds: 604800,  // 7 days
    )
    ->pauseTrigger(
        triggerKey: 'document-upload',
        timeoutSeconds: 604800,
    )
    ->produces(DocumentOutput::class)
    ->build()
```

This allows:
1. Automatic polling for status changes
2. Manual trigger via webhook when document is uploaded
3. Either path completes the step

## Workflow Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Jobs\Workflow\{
    InitiatePaymentJob,
    CheckPaymentStatusJob,
    ProcessRefundJob,
    SendReceiptJob,
};
use App\Outputs\{
    PaymentInitiationOutput,
    PaymentConfirmationOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\PollTimeoutPolicy;

final class PaymentWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Payment Processing')
            ->version(1)

            // Step 1: Initiate the payment
            ->step('initiate')
                ->name('Initiate Payment')
                ->job(InitiatePaymentJob::class)
                ->produces(PaymentInitiationOutput::class)
                ->build()

            // Step 2: Poll for confirmation
            ->polling('confirm')
                ->name('Confirm Payment')
                ->job(CheckPaymentStatusJob::class)
                ->requires('initiate', PaymentInitiationOutput::class)
                ->polling(
                    intervalSeconds: 30,
                    maxDurationSeconds: 1800,    // 30 minutes max
                    backoffMultiplier: 1.5,
                    maxIntervalSeconds: 120,
                    timeoutPolicy: PollTimeoutPolicy::PauseWorkflow,
                )
                ->produces(PaymentConfirmationOutput::class)
                ->build()

            // Step 3: Send receipt
            ->step('receipt')
                ->name('Send Receipt')
                ->job(SendReceiptJob::class)
                ->requires('confirm', PaymentConfirmationOutput::class)
                ->build();
    }
}
```

## Poll Attempt Tracking

Each poll attempt is recorded with:

- Attempt number
- Timestamp
- Result (continue/completed/aborted)
- Optional message

Query poll attempts for debugging:

```php
$attempts = $pollAttemptRepository->findByStepRunId($stepRunId);

foreach ($attempts as $attempt) {
    echo "Attempt {$attempt->attemptNumber}: {$attempt->result} at {$attempt->createdAt}\n";
}
```

## Console Commands

### Dispatch Pending Polls

Process scheduled poll attempts:

```bash
php artisan maestro:dispatch-polls
```

### Recover Stuck Polls

Find and recover polling steps that got stuck:

```bash
php artisan maestro:recover-polls --threshold=3600
```

## Best Practices

### 1. Set Reasonable Timeouts

Don't poll indefinitely - set appropriate `maxDurationSeconds`:

```php
// Good: 30-minute max for payment confirmation
->polling(intervalSeconds: 30, maxDurationSeconds: 1800)

// Risky: No practical timeout
->polling(intervalSeconds: 30, maxDurationSeconds: 999999999)
```

### 2. Use Backoff for External APIs

Reduce load on external systems:

```php
->polling(
    intervalSeconds: 10,
    maxDurationSeconds: 3600,
    backoffMultiplier: 2.0,
    maxIntervalSeconds: 300,
)
```

### 3. Log Progress

Help operators understand polling state:

```php
return new ContinuePollResult(
    message: "Checked at {$now}, status: {$status}, attempt #{$this->attemptNumber()}",
);
```

### 4. Handle All Terminal States

Ensure your polling job handles all possible outcomes:

```php
return match ($status) {
    'success' => new CompletedPollResult(...),
    'failed', 'cancelled' => new AbortedPollResult(...),
    default => new ContinuePollResult(),
};
```

## Next Steps

- [External Triggers](../advanced/external-triggers.md) - Webhook-based workflow control
- [Failure Handling](../failure-handling/overview.md) - Error recovery patterns
- [Console Commands](../../operations/console-commands.md) - Operational tooling
