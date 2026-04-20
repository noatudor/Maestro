# Early Termination

Early termination allows workflows to complete before all steps execute, based on runtime conditions. This is useful for validation gates, approval rejections, or business rules that make remaining steps unnecessary.

## How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│                   Early Termination Flow                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Step executes                                                  │
│        │                                                         │
│        ▼                                                         │
│   ┌─────────────────────┐                                       │
│   │ Termination         │                                       │
│   │ Condition Check     │                                       │
│   └──────────┬──────────┘                                       │
│              │                                                   │
│         ┌────┴────┐                                             │
│         │         │                                             │
│         ▼         ▼                                             │
│     Continue   Terminate                                        │
│         │         │                                             │
│         │         ▼                                             │
│         │   ┌───────────────────┐                               │
│         │   │ Mark remaining    │                               │
│         │   │ steps as Skipped  │                               │
│         │   │ (TerminatedEarly) │                               │
│         │   └─────────┬─────────┘                               │
│         │             │                                         │
│         │             ▼                                         │
│         │   ┌───────────────────┐                               │
│         │   │ Workflow          │                               │
│         │   │ Succeeded         │                               │
│         │   └───────────────────┘                               │
│         │                                                        │
│         ▼                                                        │
│   Next step                                                      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Basic Usage

```php
->step('check_eligibility')
    ->job(CheckEligibilityJob::class)
    ->terminationCondition(NotEligibleCondition::class)
    ->produces(EligibilityOutput::class)
    ->build()
```

When the condition returns `terminate`, remaining steps are skipped and the workflow completes successfully.

## Implementing Termination Conditions

```php
<?php

declare(strict_types=1);

namespace App\Conditions;

use App\Outputs\EligibilityOutput;
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
            // Continue with workflow
            return TerminationResult::continue();
        }

        // Terminate early
        return TerminationResult::terminate(
            reason: "Customer not eligible: {$eligibility->reason}",
        );
    }
}
```

## Use Cases

### 1. Validation Gate

Terminate if validation fails:

```php
->step('validate')
    ->job(ValidateApplicationJob::class)
    ->terminationCondition(ValidationFailedCondition::class)
    ->produces(ValidationOutput::class)
    ->build()

// Only execute if validation passed
->step('process')
    ->job(ProcessApplicationJob::class)
    ->requires('validate', ValidationOutput::class)
    ->build()
```

```php
final readonly class ValidationFailedCondition implements TerminationCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): TerminationResult {
        $validation = $outputs->get(ValidationOutput::class);

        if ($validation->isValid) {
            return TerminationResult::continue();
        }

        return TerminationResult::terminate(
            reason: implode(', ', $validation->errors),
        );
    }
}
```

### 2. Approval Rejection

Terminate if approval is denied:

```php
->step('manager_approval')
    ->job(RequestApprovalJob::class)
    ->pauseTrigger(new PauseTriggerDefinition(
        triggerKey: 'approval',
        payloadOutputClass: ApprovalOutput::class,
    ))
    ->terminationCondition(ApprovalRejectedCondition::class)
    ->build()

// These only execute if approved
->step('process_request')
    ...
```

```php
final readonly class ApprovalRejectedCondition implements TerminationCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): TerminationResult {
        $approval = $outputs->get(ApprovalOutput::class);

        if ($approval->approved) {
            return TerminationResult::continue();
        }

        return TerminationResult::terminate(
            reason: "Request rejected by {$approval->reviewerName}: {$approval->rejectionReason}",
        );
    }
}
```

### 3. Business Rule Check

Terminate based on business logic:

```php
->step('assess_risk')
    ->job(AssessRiskJob::class)
    ->terminationCondition(HighRiskCondition::class)
    ->produces(RiskAssessmentOutput::class)
    ->build()
```

```php
final readonly class HighRiskCondition implements TerminationCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): TerminationResult {
        $risk = $outputs->get(RiskAssessmentOutput::class);

        if ($risk->score < 80) {
            return TerminationResult::continue();
        }

        return TerminationResult::terminate(
            reason: "Risk score {$risk->score} exceeds threshold. Manual review required.",
        );
    }
}
```

### 4. Duplicate Detection

Terminate if already processed:

```php
->step('check_duplicate')
    ->job(CheckDuplicateJob::class)
    ->terminationCondition(AlreadyProcessedCondition::class)
    ->produces(DuplicateCheckOutput::class)
    ->build()
```

```php
final readonly class AlreadyProcessedCondition implements TerminationCondition
{
    public function evaluate(
        WorkflowContext $context,
        StepOutputReader $outputs,
    ): TerminationResult {
        $check = $outputs->get(DuplicateCheckOutput::class);

        if (!$check->isDuplicate) {
            return TerminationResult::continue();
        }

        return TerminationResult::terminate(
            reason: "Duplicate request. Original: {$check->originalRequestId}",
        );
    }
}
```

## Events

Early termination dispatches events:

```php
// Workflow terminated early
WorkflowTerminatedEarly::class
// Properties: workflowId, reason, atStepKey, terminatedAt

// Remaining steps skipped
StepSkipped::class
// Properties: workflowId, stepKey, reason (SkipReason::TerminatedEarly)
```

Monitor terminations:

```php
Event::listen(WorkflowTerminatedEarly::class, function ($event) {
    Log::info('Workflow terminated early', [
        'workflow_id' => $event->workflowId->value,
        'reason' => $event->reason,
        'at_step' => $event->atStepKey->value,
    ]);

    Metrics::increment('workflows.terminated_early');
});
```

## Workflow State

Early terminated workflows end in `Succeeded` state:

```php
$workflow = startWorkflow(MyWorkflow::class);
processWorkflow($workflow);

// If terminated early
expect($workflow->state)->toBe(WorkflowState::Succeeded);
expect($workflow->terminated_early)->toBeTrue();
expect($workflow->termination_reason)->not->toBeNull();
```

## Termination vs Failure

| Aspect | Early Termination | Failure |
|--------|-------------------|---------|
| Final State | `Succeeded` | `Failed` |
| Remaining Steps | Skipped (TerminatedEarly) | Not executed |
| Business Meaning | Expected outcome | Unexpected error |
| Compensation | Not triggered | Can trigger |
| Example | Application rejected | Payment gateway error |

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Workflows;

use App\Conditions\{
    ApplicationRejectedCondition,
    HighRiskCondition,
    DuplicateApplicationCondition,
};
use App\Jobs\Workflow\{
    CheckDuplicateJob,
    ValidateApplicationJob,
    AssessRiskJob,
    VerifyDocumentsJob,
    ApproveApplicationJob,
    NotifyApplicantJob,
};
use Maestro\Workflow\Definition\Builders\WorkflowDefinitionBuilder;
use Maestro\Workflow\Definition\WorkflowDefinition;

final class LoanApplicationWorkflow extends WorkflowDefinition
{
    public function define(WorkflowDefinitionBuilder $builder): void
    {
        $builder
            ->name('Loan Application Processing')
            ->version(1)

            // Step 1: Check for duplicate - terminate if already submitted
            ->step('check_duplicate')
                ->name('Check Duplicate')
                ->job(CheckDuplicateJob::class)
                ->terminationCondition(DuplicateApplicationCondition::class)
                ->produces(DuplicateCheckOutput::class)
                ->build()

            // Step 2: Validate application - terminate if invalid
            ->step('validate')
                ->name('Validate Application')
                ->job(ValidateApplicationJob::class)
                ->terminationCondition(ApplicationRejectedCondition::class)
                ->produces(ValidationOutput::class)
                ->build()

            // Step 3: Risk assessment - terminate if too risky
            ->step('risk_assessment')
                ->name('Assess Risk')
                ->job(AssessRiskJob::class)
                ->terminationCondition(HighRiskCondition::class)
                ->produces(RiskAssessmentOutput::class)
                ->build()

            // Step 4: Document verification (only if passed previous gates)
            ->step('verify_documents')
                ->name('Verify Documents')
                ->job(VerifyDocumentsJob::class)
                ->requires('validate', ValidationOutput::class)
                ->produces(DocumentVerificationOutput::class)
                ->build()

            // Step 5: Final approval
            ->step('approve')
                ->name('Approve Application')
                ->job(ApproveApplicationJob::class)
                ->requires('risk_assessment', RiskAssessmentOutput::class)
                ->requires('verify_documents', DocumentVerificationOutput::class)
                ->produces(ApprovalOutput::class)
                ->build()

            // Step 6: Notify applicant of result
            ->step('notify')
                ->name('Notify Applicant')
                ->job(NotifyApplicantJob::class)
                ->build();
    }
}
```

## Best Practices

### 1. Provide Clear Reasons

Always include a descriptive reason for termination:

```php
return TerminationResult::terminate(
    reason: "Application rejected: income below minimum threshold ($45,000 < $50,000)",
);
```

### 2. Use for Expected Outcomes

Early termination is for expected business outcomes, not errors:

```php
// Good: Expected outcome
return TerminationResult::terminate(reason: "Customer opted out of service");

// Bad: Should be an exception instead
return TerminationResult::terminate(reason: "Database connection failed");
```

### 3. Position Gates Early

Put termination conditions early in the workflow:

```php
// Good: Check eligibility first
->step('check_eligibility')
    ->terminationCondition(NotEligibleCondition::class)
    ->build()
->step('expensive_operation')
    ->build()

// Bad: Expensive operation before check
->step('expensive_operation')
    ->build()
->step('check_eligibility')
    ->terminationCondition(NotEligibleCondition::class)
    ->build()
```

### 4. Handle Notifications

Ensure terminated workflows notify stakeholders:

```php
Event::listen(WorkflowTerminatedEarly::class, function ($event) {
    $context = loadContext($event->workflowId);

    // Notify applicant of rejection
    Notification::send(
        $context->applicant,
        new ApplicationRejectedNotification($event->reason)
    );
});
```

## Next Steps

- [Branching](branching.md) - Conditional execution paths
- [External Triggers](external-triggers.md) - Pause for external input
- [Events Reference](../../operations/events.md) - Termination events
