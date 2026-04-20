# Document Approval Workflow

This example demonstrates a multi-level document approval workflow with external triggers, timeouts, and conditional routing.

## Workflow Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Document Approval Workflow                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌───────────────┐                                                         │
│   │    Submit     │                                                         │
│   │   Document    │                                                         │
│   └───────┬───────┘                                                         │
│           │                                                                  │
│           ▼                                                                  │
│   ┌───────────────┐                                                         │
│   │   Manager     │ ◄── External trigger: manager-approval                  │
│   │   Approval    │     Timeout: 3 days, reminder: daily                    │
│   └───────┬───────┘                                                         │
│           │                                                                  │
│      ┌────┴────┐                                                            │
│      │         │                                                            │
│      ▼         ▼                                                            │
│  Approved   Rejected                                                        │
│      │         │                                                            │
│      │         └───────────────────┐                                        │
│      │                             │                                        │
│      ▼                             │                                        │
│   ┌───────────────┐                │                                        │
│   │ High Value?   │                │                                        │
│   └───────┬───────┘                │                                        │
│           │                        │                                        │
│      ┌────┴────┐                   │                                        │
│      │         │                   │                                        │
│      ▼         ▼                   │                                        │
│   ≥$10K      <$10K                │                                        │
│      │         │                   │                                        │
│      ▼         │                   │                                        │
│   ┌───────────────┐                │                                        │
│   │  Director     │                │                                        │
│   │  Approval     │ ◄── External   │                                        │
│   └───────┬───────┘     trigger    │                                        │
│           │                        │                                        │
│      ┌────┴────┐                   │                                        │
│      │         │                   │                                        │
│      ▼         ▼                   │                                        │
│  Approved   Rejected ──────────────┤                                        │
│      │                             │                                        │
│      └──────────┬──────────────────┘                                        │
│                 │                                                            │
│                 ▼                                                            │
│   ┌───────────────┐                                                         │
│   │    Notify     │                                                         │
│   │   Requester   │                                                         │
│   └───────────────┘                                                         │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Workflow Definition

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\{
    ApprovalGrantedCondition,
    HighValueDocumentCondition,
    ManagerApprovalCondition,
    DirectorApprovalCondition,
};
use App\ContextLoaders\DocumentContextLoader;
use App\Jobs\Workflow\{
    SubmitDocumentJob,
    RequestManagerApprovalJob,
    RequestDirectorApprovalJob,
    NotifyRequesterJob,
    ArchiveDocumentJob,
};
use App\Outputs\{
    DocumentSubmissionOutput,
    ManagerApprovalOutput,
    DirectorApprovalOutput,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\Config\{BranchDefinition, PauseTriggerDefinition};
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Enums\{BranchType, FailurePolicy, TriggerTimeoutPolicy};

final class DocumentApprovalWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Document Approval')
            ->version(1)
            ->contextLoader(DocumentContextLoader::class)

            // Step 1: Submit the document
            ->step('submit')
                ->name('Submit Document')
                ->job(SubmitDocumentJob::class)
                ->produces(DocumentSubmissionOutput::class)
                ->build()

            // Step 2: Request manager approval (with external trigger)
            ->step('manager_approval')
                ->name('Manager Approval')
                ->job(RequestManagerApprovalJob::class)
                ->requires('submit', DocumentSubmissionOutput::class)
                ->pauseTrigger(new PauseTriggerDefinition(
                    triggerKey: 'manager-approval',
                    timeoutSeconds: 259200,  // 3 days
                    timeoutPolicy: TriggerTimeoutPolicy::SendReminder,
                    reminderIntervalSeconds: 86400,  // Daily
                    resumeConditionClass: ManagerApprovalCondition::class,
                    payloadOutputClass: ManagerApprovalOutput::class,
                ))
                ->produces(ManagerApprovalOutput::class)
                ->build()

            // Step 3: Branch based on approval result and value
            ->step('route_approval')
                ->name('Route Approval')
                ->job(RouteApprovalJob::class)
                ->requires('manager_approval', ManagerApprovalOutput::class)
                ->condition(ApprovalGrantedCondition::class)  // Skip if rejected
                ->branch(new BranchDefinition(
                    conditionClass: HighValueDocumentCondition::class,
                    branchType: BranchType::Exclusive,
                    branches: [
                        'high_value' => ['director_approval'],
                        'standard' => [],  // Skip to notification
                    ],
                    convergenceStepKey: 'notify',
                    defaultBranchKey: 'standard',
                ))
                ->build()

            // Step 4: Director approval for high-value documents
            ->step('director_approval')
                ->name('Director Approval')
                ->job(RequestDirectorApprovalJob::class)
                ->requires('manager_approval', ManagerApprovalOutput::class)
                ->pauseTrigger(new PauseTriggerDefinition(
                    triggerKey: 'director-approval',
                    timeoutSeconds: 604800,  // 7 days
                    timeoutPolicy: TriggerTimeoutPolicy::FailWorkflow,
                    resumeConditionClass: DirectorApprovalCondition::class,
                    payloadOutputClass: DirectorApprovalOutput::class,
                ))
                ->produces(DirectorApprovalOutput::class)
                ->build()

            // Step 5: Notify requester of result
            ->step('notify')
                ->name('Notify Requester')
                ->job(NotifyRequesterJob::class)
                ->failurePolicy(FailurePolicy::SkipStep)
                ->onQueue('notifications')
                ->build()

            // Step 6: Archive the document
            ->step('archive')
                ->name('Archive Document')
                ->job(ArchiveDocumentJob::class)
                ->build();
    }
}
```

## Context and Outputs

```php
<?php

declare(strict_types=1);

namespace App\Contexts;

use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class DocumentContext implements WorkflowContext
{
    public function __construct(
        public string $documentId,
        public string $requesterId,
        public string $requesterEmail,
        public string $documentType,
        public float $amount,
        public string $department,
        public string $managerId,
        public ?string $directorId,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Outputs;

use Maestro\Workflow\Contracts\StepOutput;

final readonly class DocumentSubmissionOutput implements StepOutput
{
    public function __construct(
        public string $documentId,
        public string $submissionId,
        public \DateTimeImmutable $submittedAt,
    ) {}
}

final readonly class ManagerApprovalOutput implements StepOutput
{
    public function __construct(
        public bool $approved,
        public string $approverId,
        public ?string $comments,
        public \DateTimeImmutable $decidedAt,
    ) {}

    public static function fromPayload(array $data): self
    {
        return new self(
            approved: $data['approved'],
            approverId: $data['approver_id'],
            comments: $data['comments'] ?? null,
            decidedAt: new \DateTimeImmutable($data['decided_at']),
        );
    }
}

final readonly class DirectorApprovalOutput implements StepOutput
{
    public function __construct(
        public bool $approved,
        public string $approverId,
        public ?string $comments,
        public ?float $approvedAmount,
        public \DateTimeImmutable $decidedAt,
    ) {}
}
```

## Resume Conditions

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use App\Models\User;
use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\ValueObjects\{ResumeConditionResult, TriggerPayload};

final readonly class ManagerApprovalCondition implements ResumeCondition
{
    public function evaluate(TriggerPayload $payload): ResumeConditionResult
    {
        // Validate required fields
        if (!isset($payload->data['approved'], $payload->data['approver_id'])) {
            return ResumeConditionResult::reject(
                reason: 'Missing required fields: approved, approver_id'
            );
        }

        // Validate approver has manager role
        $approver = User::find($payload->data['approver_id']);

        if (!$approver?->hasRole('manager')) {
            return ResumeConditionResult::reject(
                reason: 'Approver is not a manager'
            );
        }

        // Validate approver is in the same department
        // (Would need workflow context, simplified here)

        return ResumeConditionResult::accept();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use App\Outputs\ManagerApprovalOutput;
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\ConditionResult;

final readonly class ApprovalGrantedCondition implements StepCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): ConditionResult {
        $approval = $outputs->get(ManagerApprovalOutput::class);

        if ($approval->approved) {
            return ConditionResult::pass();
        }

        return ConditionResult::fail(
            reason: 'Document was rejected by manager'
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use App\Contexts\DocumentContext;
use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\BranchKey;

final readonly class HighValueDocumentCondition implements BranchCondition
{
    private const HIGH_VALUE_THRESHOLD = 10000.00;

    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): BranchKey {
        /** @var DocumentContext $context */
        if ($context->amount >= self::HIGH_VALUE_THRESHOLD) {
            return BranchKey::fromString('high_value');
        }

        return BranchKey::fromString('standard');
    }
}
```

## Job Implementations

### RequestManagerApprovalJob

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Contexts\DocumentContext;
use App\Models\User;
use App\Notifications\ApprovalRequestNotification;
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class RequestManagerApprovalJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $context = $this->contextAs(DocumentContext::class);

        $manager = User::findOrFail($context->managerId);

        // Send approval request notification
        $manager->notify(new ApprovalRequestNotification(
            documentId: $context->documentId,
            requesterId: $context->requesterId,
            amount: $context->amount,
            approvalUrl: $this->generateApprovalUrl(),
        ));

        // Store pending state
        $this->updateDocumentStatus('pending_manager_approval');
    }

    private function generateApprovalUrl(): string
    {
        return url("/documents/{$this->contextAs(DocumentContext::class)->documentId}/approve");
    }

    private function updateDocumentStatus(string $status): void
    {
        // Update document record
    }
}
```

### NotifyRequesterJob

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Workflow;

use App\Contexts\DocumentContext;
use App\Models\User;
use App\Notifications\{DocumentApprovedNotification, DocumentRejectedNotification};
use App\Outputs\{ManagerApprovalOutput, DirectorApprovalOutput};
use Maestro\Workflow\Application\Job\OrchestratedJob;

final class NotifyRequesterJob extends OrchestratedJob
{
    protected function execute(): void
    {
        $context = $this->contextAs(DocumentContext::class);
        $requester = User::findOrFail($context->requesterId);

        // Determine final approval status
        $managerApproval = $this->output(ManagerApprovalOutput::class);
        $directorApproval = $this->outputOrNull(DirectorApprovalOutput::class);

        $finalApproved = $managerApproval->approved
            && ($directorApproval === null || $directorApproval->approved);

        if ($finalApproved) {
            $requester->notify(new DocumentApprovedNotification(
                documentId: $context->documentId,
                approvalChain: $this->buildApprovalChain(),
            ));
        } else {
            $rejector = $directorApproval?->approved === false
                ? $directorApproval
                : $managerApproval;

            $requester->notify(new DocumentRejectedNotification(
                documentId: $context->documentId,
                rejectedBy: $rejector->approverId,
                reason: $rejector->comments,
            ));
        }
    }

    private function buildApprovalChain(): array
    {
        $chain = [];

        $managerApproval = $this->output(ManagerApprovalOutput::class);
        $chain[] = [
            'level' => 'Manager',
            'approved' => $managerApproval->approved,
            'approver' => $managerApproval->approverId,
            'date' => $managerApproval->decidedAt->format('Y-m-d H:i:s'),
        ];

        if ($directorApproval = $this->outputOrNull(DirectorApprovalOutput::class)) {
            $chain[] = [
                'level' => 'Director',
                'approved' => $directorApproval->approved,
                'approver' => $directorApproval->approverId,
                'date' => $directorApproval->decidedAt->format('Y-m-d H:i:s'),
            ];
        }

        return $chain;
    }
}
```

## Triggering Approvals

### Approval UI Controller

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\ValueObjects\{TriggerPayload, WorkflowId};

final class DocumentApprovalController extends Controller
{
    public function __construct(
        private readonly WorkflowManager $workflowManager,
    ) {}

    public function approve(Document $document, ApproveDocumentRequest $request)
    {
        $result = $this->workflowManager->trigger(
            workflowId: WorkflowId::fromString($document->workflow_id),
            triggerKey: 'manager-approval',
            payload: new TriggerPayload(
                type: 'approval',
                data: [
                    'approved' => true,
                    'approver_id' => auth()->id(),
                    'comments' => $request->input('comments'),
                    'decided_at' => now()->toIso8601String(),
                ],
            ),
        );

        if (!$result->accepted) {
            return back()->withErrors(['approval' => $result->rejectionReason]);
        }

        return redirect()
            ->route('documents.index')
            ->with('success', 'Document approved successfully');
    }

    public function reject(Document $document, RejectDocumentRequest $request)
    {
        $result = $this->workflowManager->trigger(
            workflowId: WorkflowId::fromString($document->workflow_id),
            triggerKey: 'manager-approval',
            payload: new TriggerPayload(
                type: 'rejection',
                data: [
                    'approved' => false,
                    'approver_id' => auth()->id(),
                    'comments' => $request->input('reason'),
                    'decided_at' => now()->toIso8601String(),
                ],
            ),
        );

        return redirect()
            ->route('documents.index')
            ->with('success', 'Document rejected');
    }
}
```

### Email Webhook Handler

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\ValueObjects\{TriggerPayload, WorkflowId};

final class EmailApprovalWebhookController extends Controller
{
    public function __construct(
        private readonly WorkflowManager $workflowManager,
    ) {}

    public function handle(Request $request)
    {
        // Parse email reply (simplified)
        $workflowId = $request->input('metadata.workflow_id');
        $triggerKey = $request->input('metadata.trigger_key');
        $approved = str_contains(strtolower($request->input('body')), 'approved');

        $this->workflowManager->trigger(
            workflowId: WorkflowId::fromString($workflowId),
            triggerKey: $triggerKey,
            payload: new TriggerPayload(
                type: 'email_reply',
                data: [
                    'approved' => $approved,
                    'approver_id' => $request->input('from_user_id'),
                    'decided_at' => now()->toIso8601String(),
                ],
            ),
        );

        return response()->json(['status' => 'processed']);
    }
}
```

## Monitoring Approvals

```php
// Listen for reminder events
Event::listen(TriggerReminderDue::class, function ($event) {
    $document = Document::where('workflow_id', $event->workflowId->value)->first();
    $manager = User::find($document->manager_id);

    $manager->notify(new ApprovalReminderNotification(
        documentId: $document->id,
        daysWaiting: $event->daysWaiting,
    ));
});

// Listen for timeout events
Event::listen(TriggerTimedOut::class, function ($event) {
    Log::warning('Approval timed out', [
        'workflow_id' => $event->workflowId->value,
        'trigger_key' => $event->triggerKey,
    ]);

    // Escalate to supervisor
    $document = Document::where('workflow_id', $event->workflowId->value)->first();
    $supervisor = User::find($document->manager->supervisor_id);

    $supervisor->notify(new ApprovalEscalationNotification($document));
});
```

## Testing

```php
<?php

declare(strict_types=1);

use App\Models\{Document, User};
use App\Workflows\DocumentApprovalWorkflow;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\ValueObjects\TriggerPayload;

describe('DocumentApprovalWorkflow', function () {
    it('completes standard approval flow', function () {
        $document = Document::factory()->create(['amount' => 5000]);

        $workflow = startWorkflow(DocumentApprovalWorkflow::class);
        $document->update(['workflow_id' => $workflow->id->value]);

        processWorkflow($workflow);

        // Should be waiting for manager approval
        expect($workflow->fresh()->state)->toBe(WorkflowState::Paused);
        expect($workflow->fresh()->trigger_key)->toBe('manager-approval');

        // Manager approves
        triggerWorkflow($workflow, 'manager-approval', new TriggerPayload(
            type: 'approval',
            data: [
                'approved' => true,
                'approver_id' => $document->manager_id,
                'decided_at' => now()->toIso8601String(),
            ],
        ));

        processWorkflow($workflow);

        // Standard value - should skip director
        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
        expect($this->wasStepSkipped($workflow, 'director_approval'))->toBeTrue();
    });

    it('routes high-value documents to director', function () {
        $document = Document::factory()->create(['amount' => 50000]);

        $workflow = startWorkflow(DocumentApprovalWorkflow::class);
        processWorkflow($workflow);

        // Manager approves
        triggerWorkflow($workflow, 'manager-approval', [
            'approved' => true,
            'approver_id' => $document->manager_id,
        ]);

        processWorkflow($workflow);

        // Should be waiting for director approval
        expect($workflow->fresh()->state)->toBe(WorkflowState::Paused);
        expect($workflow->fresh()->trigger_key)->toBe('director-approval');
    });

    it('terminates early when manager rejects', function () {
        $document = Document::factory()->create();

        $workflow = startWorkflow(DocumentApprovalWorkflow::class);
        processWorkflow($workflow);

        // Manager rejects
        triggerWorkflow($workflow, 'manager-approval', [
            'approved' => false,
            'approver_id' => $document->manager_id,
            'comments' => 'Insufficient justification',
        ]);

        processWorkflow($workflow);

        // Should skip routing and director, go straight to notify
        expect($this->wasStepSkipped($workflow, 'route_approval'))->toBeTrue();
        expect($this->wasStepExecuted($workflow, 'notify'))->toBeTrue();
        expect($workflow->fresh()->state)->toBe(WorkflowState::Succeeded);
    });
});
```

## Next Steps

- [Data Pipeline](data-pipeline.md) - ETL with polling
- [Order Processing](order-processing.md) - E-commerce example
- [Examples Overview](README.md) - All examples
