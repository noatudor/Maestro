# External Triggers

External triggers allow workflows to pause and wait for events from outside the system - webhooks, human approvals, third-party callbacks, or scheduled resumptions.

## How External Triggers Work

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        External Trigger Flow                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Workflow executing                                                         │
│         │                                                                    │
│         ▼                                                                    │
│   Step with pauseTrigger                                                     │
│         │                                                                    │
│         ▼                                                                    │
│   Step completes, trigger activated                                          │
│         │                                                                    │
│         ▼                                                                    │
│   ┌─────────────────────┐                                                   │
│   │ Workflow pauses     │                                                   │
│   │ (AwaitingTrigger)   │ ◄─── Waiting for external event                   │
│   └──────────┬──────────┘                                                   │
│              │                                                               │
│   ┌──────────┼──────────┬──────────────┐                                    │
│   │          │          │              │                                    │
│   ▼          ▼          ▼              ▼                                    │
│ Trigger   Scheduled   Timeout       Manual                                  │
│ received  resume      occurs        resume                                  │
│   │          │          │              │                                    │
│   ▼          ▼          ▼              ▼                                    │
│ Resume    Resume     Apply          Resume                                  │
│ condition checked    timeout        workflow                                │
│   │                  policy                                                 │
│   │                                                                          │
│   ▼                                                                          │
│ Workflow continues                                                           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Basic Configuration

```php
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;

->step('await_approval')
    ->job(RequestApprovalJob::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'manager-approval',
        timeoutSeconds: 86400,  // 24 hours
    ))
    ->produces(ApprovalOutput::class)
    ->build()
```

## Pause Trigger Configuration

### Full Configuration

```php
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;

->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'document-signature',
    timeoutSeconds: 604800,              // 7 days
    timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
    scheduledResumeSeconds: null,        // No auto-resume
    resumeConditionClass: SignatureResumeCondition::class,
    payloadOutputClass: SignaturePayloadOutput::class,
    reminderIntervalSeconds: 86400,      // Daily reminders
))
```

### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `triggerKey` | string | Unique identifier for the trigger |
| `timeoutSeconds` | int | Time before timeout (default: 604800 = 7 days) |
| `timeoutPolicy` | TriggerTimeoutPolicy | What happens on timeout |
| `scheduledResumeSeconds` | ?int | Auto-resume after this many seconds |
| `resumeConditionClass` | ?string | Condition to validate trigger payload |
| `payloadOutputClass` | ?string | Store trigger payload as step output |
| `reminderIntervalSeconds` | ?int | Interval between reminder notifications |

## Sending Triggers

### HTTP API

```http
POST /api/maestro/workflows/{workflowId}/trigger/{triggerKey}
Content-Type: application/json
X-Maestro-Signature: {hmac_signature}  # If HMAC auth enabled

{
    "type": "approval",
    "payload": {
        "approved": true,
        "approver_id": "user-123",
        "approved_at": "2024-01-15T10:30:00Z",
        "notes": "Approved with conditions"
    }
}
```

Response:

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "trigger_key": "manager-approval",
    "message": "Trigger accepted, workflow resumed"
}
```

### Programmatically

```php
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\ValueObjects\TriggerPayload;

$result = $workflowManager->trigger(
    workflowId: $workflowId,
    triggerKey: 'manager-approval',
    payload: new TriggerPayload(
        type: 'approval',
        data: [
            'approved' => true,
            'approver_id' => $user->id,
        ],
    ),
);

if ($result->accepted) {
    // Workflow resumed
} else {
    // Trigger rejected (condition failed or workflow not waiting)
}
```

## Resume Conditions

Validate trigger payloads before accepting:

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\ValueObjects\{ResumeConditionResult, TriggerPayload};

final readonly class ApprovalResumeCondition implements ResumeCondition
{
    public function evaluate(TriggerPayload $payload): ResumeConditionResult
    {
        // Validate required fields
        if (!isset($payload->data['approved'])) {
            return ResumeConditionResult::reject(
                reason: 'Missing approval status'
            );
        }

        // Validate approver has authority
        if (!$this->hasApprovalAuthority($payload->data['approver_id'] ?? null)) {
            return ResumeConditionResult::reject(
                reason: 'Approver lacks authority'
            );
        }

        return ResumeConditionResult::accept();
    }

    private function hasApprovalAuthority(?string $approverId): bool
    {
        if (!$approverId) {
            return false;
        }

        return User::find($approverId)?->can('approve_workflows') ?? false;
    }
}
```

## Storing Trigger Payload

Store the trigger payload as step output for use by later steps:

```php
->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'document-upload',
    payloadOutputClass: DocumentUploadOutput::class,
))
```

Define the output class:

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class DocumentUploadOutput implements StepOutput
{
    public function __construct(
        public string $documentId,
        public string $uploadedBy,
        public string $fileName,
        public string $fileUrl,
        public \DateTimeImmutable $uploadedAt,
    ) {}

    public static function fromPayload(array $data): self
    {
        return new self(
            documentId: $data['document_id'],
            uploadedBy: $data['uploaded_by'],
            fileName: $data['file_name'],
            fileUrl: $data['file_url'],
            uploadedAt: new \DateTimeImmutable($data['uploaded_at']),
        );
    }
}
```

Access in subsequent steps:

```php
final class ProcessDocumentJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $upload = $this->output(DocumentUploadOutput::class);

        $this->documentProcessor->process($upload->fileUrl);
    }
}
```

## Timeout Policies

Configure what happens when trigger times out:

### FailWorkflow

```php
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;

->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'approval',
    timeoutSeconds: 86400,
    timeoutPolicy: TriggerTimeoutPolicy::FailWorkflow,
))
```

Workflow transitions to `Failed` state.

### SendReminder

```php
->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'approval',
    timeoutSeconds: 604800,  // 7 days
    timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
    reminderIntervalSeconds: 86400,  // Daily
))
```

- Workflow stays paused
- `TriggerReminderDue` event dispatched periodically
- Eventually times out (configurable)

### AutoResume

```php
->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'review',
    timeoutSeconds: 259200,  // 3 days
    timeoutPolicy: TriggerTimeoutPolicy::AutoResume,
    defaultPayloadClass: DefaultApprovalOutput::class,
))
```

Automatically resume with default payload when timeout occurs.

### ExtendTimeout

```php
->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'signature',
    timeoutSeconds: 86400,
    timeoutPolicy: TriggerTimeoutPolicy::ExtendTimeout,
    extensionSeconds: 86400,  // Extend by 1 day
    maxExtensions: 3,
))
```

Extend the deadline when timeout is reached (up to max extensions).

## Scheduled Resumption

Auto-resume after a fixed delay:

```php
->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'cooling-off',
    scheduledResumeSeconds: 86400,  // Resume after 24 hours
))
```

The workflow automatically resumes after the specified time, regardless of trigger.

## Authentication

### HMAC Authentication

Configure in `config/maestro.php`:

```php
'trigger_auth' => [
    'driver' => 'hmac',
    'hmac' => [
        'secret' => env('MAESTRO_TRIGGER_SECRET'),
        'max_timestamp_drift_seconds' => 300,  // 5 minutes
    ],
],
```

Signature format:

```
X-Maestro-Timestamp: 1705312200
X-Maestro-Signature: sha256={computed_signature}
```

Compute signature:

```php
$timestamp = time();
$payload = json_encode($data);
$stringToSign = "{$timestamp}.{$payload}";
$signature = hash_hmac('sha256', $stringToSign, $secret);

// Header value
"sha256={$signature}"
```

### Null Authentication

For development or internal triggers:

```php
'trigger_auth' => [
    'driver' => 'null',  // No authentication
],
```

## Events

External triggers dispatch these events:

```php
// Workflow waiting for trigger
WorkflowAwaitingTrigger::class
// Properties: workflowId, triggerKey, timeoutAt

// Trigger received
TriggerReceived::class
// Properties: workflowId, triggerKey, payload, accepted

// Trigger validation failed
TriggerValidationFailed::class
// Properties: workflowId, triggerKey, payload, reason

// Trigger timed out
TriggerTimedOut::class
// Properties: workflowId, triggerKey, timeoutPolicy

// Workflow auto-resumed on schedule
WorkflowAutoResumed::class
// Properties: workflowId, scheduledFor
```

Monitor triggers:

```php
Event::listen(WorkflowAwaitingTrigger::class, function ($event) {
    // Send notification to approvers
    Notification::send(
        $this->getApprovers($event->workflowId),
        new ApprovalRequestedNotification($event->workflowId)
    );
});

Event::listen(TriggerTimedOut::class, function ($event) {
    Alert::warning("Trigger {$event->triggerKey} timed out for workflow {$event->workflowId}");
});
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\ManagerApprovalCondition;
use App\Jobs\Workflow\{
    CreatePurchaseRequestJob,
    NotifyManagerJob,
    ProcessPurchaseJob,
    NotifyRequesterJob,
};
use App\Outputs\{
    PurchaseRequestOutput,
    ApprovalOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\TriggerTimeoutPolicy;

final class PurchaseApprovalWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Purchase Approval')
            ->version(1)

            // Create the purchase request
            ->step('create_request')
                ->job(CreatePurchaseRequestJob::class)
                ->produces(PurchaseRequestOutput::class)
                ->build()

            // Notify manager and wait for approval
            ->step('await_approval')
                ->job(NotifyManagerJob::class)
                ->requires('create_request', PurchaseRequestOutput::class)
                ->pauseTrigger(new PauseTriggerDefinition(
                    triggerKey: 'manager-approval',
                    timeoutSeconds: 259200,  // 3 days
                    timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
                    reminderIntervalSeconds: 86400,  // Daily reminders
                    resumeConditionClass: ManagerApprovalCondition::class,
                    payloadOutputClass: ApprovalOutput::class,
                ))
                ->produces(ApprovalOutput::class)
                ->build()

            // Process the approved purchase
            ->step('process_purchase')
                ->job(ProcessPurchaseJob::class)
                ->requires('await_approval', ApprovalOutput::class)
                ->condition(ApprovalGrantedCondition::class)
                ->build()

            // Notify the requester
            ->step('notify_requester')
                ->job(NotifyRequesterJob::class)
                ->requires('await_approval', ApprovalOutput::class)
                ->build();
    }
}
```

## Webhook Integration Example

```php
// routes/api.php
Route::post('/webhooks/docusign', function (Request $request) {
    $workflowId = $request->input('envelope_custom_field_workflow_id');

    $result = app(WorkflowManager::class)->trigger(
        workflowId: WorkflowId::fromString($workflowId),
        triggerKey: 'document-signed',
        payload: new TriggerPayload(
            type: 'docusign_webhook',
            data: [
                'envelope_id' => $request->input('envelope_id'),
                'status' => $request->input('status'),
                'signed_at' => $request->input('completed_at'),
                'signer_email' => $request->input('signer_email'),
            ],
        ),
    );

    return response()->json(['accepted' => $result->accepted]);
});
```

## Console Commands

### Check Trigger Timeouts

```bash
# Process timed-out triggers
php artisan maestro:check-trigger-timeouts
```

### Process Scheduled Resumes

```bash
# Resume workflows with scheduled resume times
php artisan maestro:process-scheduled-resumes
```

Add to scheduler:

```php
$schedule->command('maestro:check-trigger-timeouts')
    ->everyFiveMinutes();

$schedule->command('maestro:process-scheduled-resumes')
    ->everyMinute();
```

## Best Practices

### 1. Use Descriptive Trigger Keys

```php
// Good: Clear purpose
'triggerKey' => 'manager-approval'
'triggerKey' => 'document-signed'
'triggerKey' => 'payment-confirmed'

// Bad: Vague
'triggerKey' => 'trigger1'
'triggerKey' => 'wait'
```

### 2. Validate Trigger Payloads

Always use resume conditions for external triggers:

```php
->pauseTrigger(new PauseTriggerDefinition(
    triggerKey: 'webhook',
    resumeConditionClass: WebhookPayloadCondition::class,
))
```

### 3. Set Appropriate Timeouts

Consider the expected response time:

```php
// Human approval: days
timeoutSeconds: 259200  // 3 days

// Webhook callback: hours
timeoutSeconds: 3600    // 1 hour

// Immediate response expected: minutes
timeoutSeconds: 300     // 5 minutes
```

### 4. Handle Rejection Gracefully

When a trigger is rejected, inform the sender:

```php
$result = $workflowManager->trigger(...);

if (!$result->accepted) {
    Log::warning('Trigger rejected', [
        'workflow_id' => $workflowId,
        'reason' => $result->rejectionReason,
    ]);

    return response()->json([
        'error' => 'Trigger rejected',
        'reason' => $result->rejectionReason,
    ], 400);
}
```

## Next Steps

- [Scheduled Resumption](scheduled-resumption.md) - Automatic workflow resumption
- [Early Termination](early-termination.md) - Conditional workflow completion
- [API Reference](../../operations/api-reference.md) - Trigger API endpoints
