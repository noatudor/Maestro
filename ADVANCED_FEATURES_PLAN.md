# Maestro Advanced Features - Architecture & Implementation Plan

This document specifies additional phases for advanced workflow orchestration features, building on the foundation established in phases 1-15.

---

## Table of Contents

1. Overview
2. Phase 16: Failure Resolution Strategy
3. Phase 17: Retry From Step
4. Phase 18: Compensation System (Saga Pattern)
5. Phase 19: Conditional Branching & Early Termination
6. Phase 20: Polling Steps & Time-Bounded Loops
7. Phase 21: Automatic Pause Triggers
8. Persistence Model Extensions
9. State Machine Updates
10. Implementation Order & Dependencies

---

## 1. Overview

### Feature Summary

| Feature | Purpose | Use Case Examples |
|---------|---------|-------------------|
| **Failure Resolution** | Configure how workflow handles failures | Choose between manual decision, auto-retry, or auto-compensate |
| **Retry From Step** | Re-run workflow from a specific step | Fix data issue, then continue from step 3 |
| **Compensations** | Rollback side effects when needed | Cancel payment after shipping fails; release inventory |
| **Conditional Branching** | Execute different paths based on step output | Route to different fulfillment centers; skip optional steps |
| **Polling Steps** | Repeatedly execute until condition met | Wait for external system status; poll for file availability |
| **Automatic Pause Triggers** | Pause workflow awaiting external action | Human approval; manual verification; external webhook |

### Design Principles

These features follow the same principles established in the architecture spec:

- **Explicit over implicit**: All behavior defined declaratively in workflow definition
- **Queryable over convenient**: State persisted in relational tables, not JSON blobs
- **Typed over dynamic**: Conditions, compensation references, and triggers use typed contracts
- **Auditable over minimal**: Every resolution decision, retry, and compensation is recorded
- **Event-driven**: No polling daemons; advancement triggered by events and scheduled checks
- **Scheduler-based timing**: Time-bound features (polling, triggers) use Laravel scheduler with stored timestamps, not delayed dispatch
- **Manual control preferred**: Default to human decision for failures; automation is opt-in

---

## 2. Phase 16: Failure Resolution Strategy

### 2.1 Objective

Make failure handling configurable per workflow definition, allowing operators to choose between manual intervention, automatic retry, or automatic compensation.

### 2.2 The Problem

Without configurable failure resolution:
- All failures require manual intervention, OR
- All failures trigger automatic compensation
- No flexibility for different workflow requirements
- No ability to inspect and decide on a case-by-case basis

### 2.3 Failure Resolution Strategies

```
┌─────────────────────────────────────────────────────────────────┐
│                 Failure Resolution Strategies                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. AWAIT_DECISION (default, recommended)                        │
│     ├── Workflow transitions to FAILED state                    │
│     ├── Waits for manual intervention                           │
│     ├── Operator inspects failure and chooses action            │
│     └── Options: retry, compensate, cancel, or mark resolved    │
│                                                                  │
│  2. AUTO_RETRY                                                   │
│     ├── Automatically retry from failed step                    │
│     ├── Configurable max retry attempts                         │
│     ├── Configurable retry delay (backoff)                      │
│     └── After max retries, falls back to AWAIT_DECISION         │
│                                                                  │
│  3. AUTO_COMPENSATE                                              │
│     ├── Immediately triggers compensation on failure            │
│     ├── No manual intervention required                         │
│     ├── Requires compensation jobs defined on steps             │
│     └── Use only for fully automated rollback scenarios         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.4 Workflow Definition Configuration

```
┌─────────────────────────────────────────────────────────────────┐
│                  Workflow Definition                             │
├─────────────────────────────────────────────────────────────────┤
│  Definition Key: "order-fulfillment"                             │
│  Version: "2.1.0"                                                │
│  Steps: [...]                                                    │
│                                                                  │
│  Failure Resolution Configuration:                               │
│  ├── Strategy: AWAIT_DECISION | AUTO_RETRY | AUTO_COMPENSATE    │
│  │                                                               │
│  │   If AUTO_RETRY:                                              │
│  │   ├── Max Retries: 3                                         │
│  │   ├── Retry Delay: 60 seconds (or backoff strategy)          │
│  │   └── Fallback: AWAIT_DECISION (after max retries)           │
│  │                                                               │
│  │   If AUTO_COMPENSATE:                                         │
│  │   └── Compensation Scope: ALL | FAILED_STEP_ONLY             │
│  │                                                               │
│  ├── On Cancel: COMPENSATE | NO_COMPENSATE                      │
│  └── On Timeout: COMPENSATE | FAIL | AWAIT_DECISION             │
└─────────────────────────────────────────────────────────────────┘
```

### 2.5 Resolution Decision Flow

```
                     Workflow Failure Detected
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Load failure resolution     │
               │  strategy from definition    │
               └──────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
          ▼                   ▼                   ▼
    AWAIT_DECISION      AUTO_RETRY        AUTO_COMPENSATE
          │                   │                   │
          ▼                   ▼                   ▼
   ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
   │ Workflow    │    │ Check retry │    │ Trigger     │
   │ state:      │    │ attempts    │    │ compensation│
   │ FAILED      │    │ remaining   │    │ (Phase 18)  │
   └─────────────┘    └──────┬──────┘    └─────────────┘
          │                  │
          │           ┌──────┴──────┐
   Await manual       │             │
   intervention    Retries       Max retries
          │        remaining     exceeded
          │           │             │
          │           ▼             ▼
          │    ┌─────────────┐  Fall back to
          │    │ Schedule    │  AWAIT_DECISION
          │    │ retry after │       │
          │    │ delay       │       │
          │    └─────────────┘       │
          │                          │
          └──────────────────────────┘
```

### 2.6 Manual Resolution Options (AWAIT_DECISION)

When workflow is in FAILED state awaiting decision:

```
┌─────────────────────────────────────────────────────────────────┐
│                 Manual Resolution Options                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Option A: RETRY_FAILED_STEP                                     │
│  ├── Re-run only the step that failed                           │
│  ├── Outputs from previous steps preserved                      │
│  └── Workflow continues if retry succeeds                       │
│                                                                  │
│  Option B: RETRY_FROM_STEP (see Phase 17)                        │
│  ├── Re-run workflow starting from a specific step              │
│  ├── Can choose any completed step to restart from              │
│  └── Later step outputs cleared, optionally compensated         │
│                                                                  │
│  Option C: COMPENSATE (see Phase 18)                             │
│  ├── Execute compensation jobs for completed steps              │
│  ├── Can select: all steps or specific steps                    │
│  └── Workflow transitions to COMPENSATING state                 │
│                                                                  │
│  Option D: CANCEL                                                │
│  ├── Mark workflow as CANCELLED                                 │
│  ├── No compensation (side effects remain)                      │
│  └── Terminal state, no further action possible                 │
│                                                                  │
│  Option E: MARK_RESOLVED                                         │
│  ├── Mark workflow as terminal FAILED                           │
│  ├── Records resolution reason/notes                            │
│  └── Use when manual cleanup done outside the system            │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.7 Resolution Decision Tracking

New table: `maestro_resolution_decisions`

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| decision_type | Enum | RETRY, RETRY_FROM_STEP, COMPENSATE, CANCEL, MARK_RESOLVED |
| decided_by | String, nullable | User/system that made decision |
| reason | Text, nullable | Explanation for the decision |
| retry_from_step_key | String, nullable | If RETRY_FROM_STEP, which step |
| compensate_step_keys | JSON, nullable | If COMPENSATE, which steps (null = all) |
| created_at | Timestamp | When decision was made |

### 2.8 Implementation Steps

16.1. **Create Failure Resolution Contracts**
   - `FailureResolutionStrategy` enum (AwaitDecision, AutoRetry, AutoCompensate)
   - `ResolutionDecision` enum (Retry, RetryFromStep, Compensate, Cancel, MarkResolved)
   - `FailureResolutionConfig` value object

16.2. **Extend Workflow Definition**
   - Add `failureResolution()` method to workflow builder
   - Strategy configuration
   - Auto-retry settings (max retries, delay, fallback)
   - Cancel/timeout behavior configuration

16.3. **Create Resolution Decision Persistence**
   - Migration for `maestro_resolution_decisions` table
   - `ResolutionDecisionModel` Eloquent model
   - `ResolutionDecisionRepository` interface and implementation

16.4. **Create Resolution Handler Service**
   - `FailureResolutionHandler` service
   - Apply configured strategy on workflow failure
   - Handle AUTO_RETRY scheduling via Laravel scheduler
   - Emit resolution events

16.5. **Extend Workflow Management Service**
   - Add `resolveFailure(WorkflowId, ResolutionDecision)` method
   - Validate decision is allowed for current state
   - Record decision and execute action

16.6. **Create Resolution Commands**
   - `maestro:resolve` - Interactive command to resolve failed workflow
   - `maestro:process-auto-retries` - Scheduled command for AUTO_RETRY

16.7. **Create Resolution Events**
   - `WorkflowAwaitingResolution` event
   - `ResolutionDecisionMade` event
   - `AutoRetryScheduled` event
   - `AutoRetryExhausted` event

---

## 3. Phase 17: Retry From Step

### 3.1 Objective

Enable re-running a workflow from any previously completed step, not just the failed one. This allows recovery scenarios where the issue occurred in an earlier step.

### 3.2 Use Cases

| Scenario | Solution |
|----------|----------|
| Data was wrong in step 2, discovered at step 5 | Retry from step 2 |
| External system state changed | Retry from affected step |
| Step succeeded but with wrong output | Retry that specific step |
| Need to re-process after fix deployed | Retry from relevant step |

### 3.3 Retry From Step Concept

```
┌─────────────────────────────────────────────────────────────────┐
│                    Retry From Step                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Current workflow state (FAILED at Step D):                      │
│  Step A (✓) → Step B (✓) → Step C (✓) → Step D (✗)              │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│  Option 1: Retry from Step D (the failed step)                   │
│  Step A (✓) → Step B (✓) → Step C (✓) → Step D (▶ retry)        │
│  • Only failed step re-runs                                      │
│  • All previous outputs preserved                                │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│  Option 2: Retry from Step B (earlier step)                      │
│  Step A (✓) → Step B (▶ retry) → Step C (reset) → Step D (reset)│
│  • Step B and all following steps re-run                         │
│  • Outputs from C and D are cleared                              │
│  • Step A output preserved                                       │
│                                                                  │
│  ─────────────────────────────────────────────────────────────  │
│                                                                  │
│  Option 3: Retry from Step B WITH compensation                   │
│  1. Compensate Step C (if it has side effects)                   │
│  2. Then: Step B (▶ retry) → Step C (reset) → Step D (reset)    │
│  • Side effects from C are rolled back before retry              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 3.4 Retry Modes

| Mode | Behavior | When to Use |
|------|----------|-------------|
| `RETRY_ONLY` | Clear outputs, re-run steps | Steps have no external side effects |
| `COMPENSATE_THEN_RETRY` | Compensate affected steps first, then re-run | Steps have side effects that must be undone |

### 3.5 Retry From Step Flow

```
                  Retry From Step X Requested
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Validate:                    │
               │  - Step X exists             │
               │  - Step X was completed      │
               │  - Workflow is in FAILED     │
               └──────────────────────────────┘
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Identify affected steps:    │
               │  - All steps after X         │
               │  - That were completed       │
               └──────────────────────────────┘
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Compensation needed?        │
               │  (based on retry mode)       │
               └──────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
        RETRY_ONLY              COMPENSATE_THEN_RETRY
              │                               │
              │                               ▼
              │                ┌──────────────────────────┐
              │                │ Execute compensation for │
              │                │ steps after X            │
              │                │ (reverse order)          │
              │                └──────────────────────────┘
              │                               │
              └───────────────┬───────────────┘
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Clear outputs for steps     │
               │  X and after                 │
               └──────────────────────────────┘
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Reset step runs:            │
               │  - Mark previous as SUPERSEDED│
               │  - Create new step run for X │
               └──────────────────────────────┘
                              │
                              ▼
               ┌──────────────────────────────┐
               │  Workflow state: RUNNING     │
               │  Current step: X             │
               │  Dispatch step X             │
               └──────────────────────────────┘
```

### 3.6 Step Run States (Extended)

New state for superseded step runs:

| State | Description |
|-------|-------------|
| SUPERSEDED | Step run was replaced by a retry-from-step operation |

This preserves the audit trail while indicating the step run is no longer the "current" one.

### 3.7 Retry Tracking

Extend `maestro_step_runs` table:

| Column | Type | Purpose |
|--------|------|---------|
| superseded_by_id | Foreign key, nullable | Points to the new step run that replaced this one |
| superseded_at | Timestamp, nullable | When this step run was superseded |
| retry_source | Enum, nullable | FAILED_RETRY, RETRY_FROM_STEP, AUTO_RETRY |

### 3.8 Implementation Steps

17.1. **Create Retry From Step Contracts**
   - `RetryMode` enum (RetryOnly, CompensateThenRetry)
   - `RetryFromStepRequest` value object
   - `RetryFromStepResult` value object

17.2. **Create Step Superseding Logic**
   - Add SUPERSEDED state to StepRunState enum
   - Logic to mark step runs as superseded
   - Clear outputs for superseded steps

17.3. **Create Retry From Step Service**
   - `RetryFromStepService` class
   - Validate retry request
   - Identify affected steps
   - Coordinate with compensation (if needed)
   - Execute retry

17.4. **Extend Workflow Management Service**
   - Add `retryFromStep(WorkflowId, StepKey, RetryMode)` method
   - Integrate with resolution decision flow

17.5. **Create Retry Commands**
   - `maestro:retry-from` - Retry workflow from specific step
   - Interactive step selection

17.6. **Create Retry Events**
   - `RetryFromStepInitiated` event
   - `StepRunSuperseded` event
   - `RetryFromStepCompleted` event

---

## 4. Phase 18: Compensation System (Saga Pattern)

### 4.1 Objective

Implement the Saga pattern for rolling back completed steps by executing compensation jobs in reverse order.

### 4.2 Concept

```
┌─────────────────────────────────────────────────────────────────┐
│                    Saga Compensation Pattern                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Normal Execution (forward):                                     │
│  Step A ──▶ Step B ──▶ Step C ──▶ Step D (fails or compensate)  │
│                                                                  │
│  Compensation Execution (backward):                              │
│  Compensate C ◀── Compensate B ◀── Compensate A                 │
│                                                                  │
│  Key principles:                                                 │
│  • Only COMPLETED steps can be compensated                       │
│  • Compensation is OPTIONAL per step                             │
│  • Compensation runs in REVERSE order                            │
│  • Each compensation has its own retry policy                    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 4.3 Compensation Definition Model

Each step can optionally declare a compensation job:

```
┌─────────────────────────────────────────────────────────────────┐
│                  Step Definition (Extended)                      │
├─────────────────────────────────────────────────────────────────┤
│  Key: "reserve-inventory"                                        │
│  Job: ReserveInventoryJob::class                                 │
│  Produces: InventoryReservedOutput                               │
│                                                                  │
│  Compensation (optional):                                        │
│  ├── Job: ReleaseInventoryJob::class                            │
│  ├── Receives: InventoryReservedOutput (knows what to undo)     │
│  ├── Timeout: 60 seconds                                        │
│  └── Retry: 3 attempts with exponential backoff                 │
└─────────────────────────────────────────────────────────────────┘
```

### 4.4 Compensation Triggers

Compensation can be triggered by:

| Trigger | Source | Scope |
|---------|--------|-------|
| Manual decision | Operator via API/CLI | Selected steps or all |
| AUTO_COMPENSATE strategy | Automatic on failure | All completed steps |
| COMPENSATE_THEN_RETRY | Before retry from step | Steps after retry point |
| Cancel with compensation | Workflow cancellation | All completed steps |

### 4.5 Compensation Scope Options

```
┌─────────────────────────────────────────────────────────────────┐
│                 Compensation Scope                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  FULL: Compensate all completed steps                            │
│  Step A (✓) → Step B (✓) → Step C (✓) → Step D (✗)              │
│  Compensate: C, B, A (reverse order)                             │
│                                                                  │
│  PARTIAL: Compensate selected steps only                         │
│  Step A (✓) → Step B (✓) → Step C (✓) → Step D (✗)              │
│  Compensate: C only (operator selected)                          │
│                                                                  │
│  FROM_STEP: Compensate from a specific step onwards              │
│  Step A (✓) → Step B (✓) → Step C (✓) → Step D (✗)              │
│  Compensate from B: C, B (used by retry-from-step)               │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 4.6 Compensation Execution Flow

```
                Compensation Triggered
          (manual, auto, or retry-from-step)
                          │
                          ▼
           ┌──────────────────────────────┐
           │  Determine compensation scope │
           │  - Which steps to compensate │
           └──────────────────────────────┘
                          │
                          ▼
           ┌──────────────────────────────┐
           │  Filter to steps that:       │
           │  - Are completed             │
           │  - Have compensation defined │
           │  - Are in scope              │
           └──────────────────────────────┘
                          │
                          ▼
           ┌──────────────────────────────┐
           │  Order steps in reverse      │
           │  execution order             │
           └──────────────────────────────┘
                          │
                          ▼
           ┌──────────────────────────────┐
           │  Workflow state: COMPENSATING│
           │  Create CompensationRun      │
           │  records for each step       │
           └──────────────────────────────┘
                          │
                          ▼
           ┌──────────────────────────────┐
           │  Execute compensations       │◀────┐
           │  sequentially (one at a time)│     │
           └──────────────────────────────┘     │
                          │                     │
                          ▼                     │
                ┌─────────────────┐            │
                │ Compensation    │            │
                │ succeeded?      │            │
                └────────┬────────┘            │
                         │                     │
           ┌─────────────┴─────────────┐      │
           │ YES                   NO  │      │
           ▼                       ▼   │      │
    ┌───────────┐         ┌───────────┐│      │
    │ Next      │         │ Retry     ││      │
    │ compensation│       │ (if       │├──────┘
    │ in list   │         │ attempts  ││
    └─────┬─────┘         │ remaining)││
          │               └───────────┘│
          ▼                        ▼   │
    ┌───────────┐         ┌───────────┐│
    │ All done? │         │ Max       ││
    └─────┬─────┘         │ retries?  ││
          │               └─────┬─────┘│
    ┌─────┴─────┐               │      │
    │           │         ┌─────┴──────┐
   YES          NO        │COMPENSATION│
    │           │         │_FAILED     │
    ▼           └─────────│(await fix) │
┌───────────┐             └────────────┘
│ Workflow  │
│COMPENSATED│
└───────────┘
```

### 4.7 Workflow States (Extended)

| State | Description | Valid Transitions |
|-------|-------------|-------------------|
| COMPENSATING | Compensation in progress | COMPENSATED, COMPENSATION_FAILED |
| COMPENSATED | All compensations completed successfully | (terminal) |
| COMPENSATION_FAILED | Compensation failed, needs intervention | COMPENSATING (retry), COMPENSATED (skip remaining) |

### 4.8 Compensation Tracking Table

New table: `maestro_compensation_runs`

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| step_key | String | Which step's compensation |
| compensation_job_class | String | The compensation job class |
| status | Enum | PENDING, RUNNING, SUCCEEDED, FAILED, SKIPPED |
| attempt | Integer | Current attempt number |
| max_attempts | Integer | Maximum retry attempts |
| started_at | Timestamp, nullable | When compensation began |
| finished_at | Timestamp, nullable | When compensation completed |
| failure_message | Text, nullable | Error details if failed |
| failure_trace | Text, nullable | Stack trace if failed |
| created_at | Timestamp | Creation time |
| updated_at | Timestamp | Last update time |

### 4.9 Compensation Job Base

```
┌─────────────────────────────────────────────────────────────────┐
│                    Compensation Job                              │
├─────────────────────────────────────────────────────────────────┤
│  Extends: OrchestratedJob                                        │
│                                                                  │
│  Receives:                                                       │
│  ├── Workflow context (same as forward job)                     │
│  ├── Step output from the step being compensated                │
│  └── Compensation metadata (attempt, reason)                    │
│                                                                  │
│  Responsibilities:                                               │
│  ├── Undo the side effects of the forward step                  │
│  ├── Be idempotent (safe to retry)                              │
│  └── Throw exception if compensation fails                      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 4.10 Implementation Steps

18.1. **Create Compensation Contracts**
   - `CompensationJob` abstract base class
   - `CompensationScope` enum (Full, Partial, FromStep)
   - `CompensationRunStatus` enum
   - `CompensationDefinition` value object

18.2. **Extend Step Definition**
   - Add `compensation()` method to step builder
   - Compensation job class reference
   - Compensation timeout and retry settings

18.3. **Create Compensation Persistence**
   - Migration for `maestro_compensation_runs` table
   - `CompensationRunModel` Eloquent model
   - `CompensationRunRepository` interface and implementation

18.4. **Create Compensation Executor**
   - `CompensationExecutor` service
   - Build compensation plan based on scope
   - Execute compensations sequentially
   - Handle retries and failures

18.5. **Integrate with Workflow States**
   - Add COMPENSATING, COMPENSATED, COMPENSATION_FAILED states
   - State transition validation
   - Update WorkflowAdvancer for compensation states

18.6. **Create Compensation Commands**
   - `maestro:compensate` - Trigger compensation manually
   - `maestro:skip-compensation` - Skip a stuck compensation step
   - `maestro:retry-compensation` - Retry failed compensation

18.7. **Create Compensation Events**
   - `CompensationStarted` event
   - `CompensationStepStarted` event
   - `CompensationStepSucceeded` event
   - `CompensationStepFailed` event
   - `CompensationCompleted` event
   - `CompensationFailed` event

---

## 5. Phase 19: Conditional Branching & Early Termination

### 5.1 Objective

Enable workflows to:
- Execute different step paths based on runtime conditions
- Terminate early without executing remaining steps
- Skip optional steps based on output values

### 5.2 Branching Concept

```
┌─────────────────────────────────────────────────────────────────┐
│                    Conditional Branching                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Step: validate-order                                           │
│            │                                                    │
│            ▼                                                    │
│  ┌─────────────────────┐                                        │
│  │ Condition Evaluator │                                        │
│  │ (reads step output) │                                        │
│  └─────────────────────┘                                        │
│            │                                                    │
│  ┌─────────┼─────────────────────┐                              │
│  │         │                     │                              │
│  ▼         ▼                     ▼                              │
│ HIGH     STANDARD              LOW                              │
│ PRIORITY PRIORITY            PRIORITY                           │
│  │         │                     │                              │
│  ▼         ▼                     ▼                              │
│ express- standard-           queue-for-                         │
│ fulfill  fulfill              batch                             │
│  │         │                     │                              │
│  └─────────┴─────────────────────┘                              │
│            │                                                    │
│            ▼                                                    │
│       send-confirmation (convergence point)                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 5.3 Branch Types

| Type | Description | Use Case |
|------|-------------|----------|
| **Conditional Skip** | Skip a step if condition false | Skip discount step if no coupon |
| **Exclusive Branch** | Execute exactly one of N paths | Route to regional fulfillment center |
| **Inclusive Branch** | Execute one or more of N paths | Apply multiple applicable promotions |
| **Early Termination** | End workflow immediately | Order validation failed, no further processing |

### 5.4 Condition Definition Model

```
┌─────────────────────────────────────────────────────────────────┐
│                    Condition Contracts                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  interface StepCondition                                         │
│  {                                                               │
│      // Returns true if step should execute                      │
│      evaluate(StepOutputStore $outputs): bool                    │
│  }                                                               │
│                                                                  │
│  interface BranchCondition                                       │
│  {                                                               │
│      // Returns the branch key(s) to follow                      │
│      evaluate(StepOutputStore $outputs): array<string>           │
│  }                                                               │
│                                                                  │
│  interface TerminationCondition                                  │
│  {                                                               │
│      // Returns true if workflow should terminate                │
│      shouldTerminate(StepOutputStore $outputs): bool             │
│                                                                  │
│      // Terminal state and reason                                │
│      terminalState(): WorkflowState  // SUCCEEDED or FAILED      │
│      terminationReason(): string                                 │
│  }                                                               │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 5.5 Early Termination Model

```
                    Step Completes
                          │
                          ▼
              ┌───────────────────────┐
              │ Check termination     │
              │ conditions (if any)   │
              └───────────────────────┘
                          │
              ┌───────────┴───────────┐
              │                       │
        Should terminate        Continue normally
              │                       │
              ▼                       ▼
    ┌─────────────────┐     ┌─────────────────┐
    │ Record decision │     │ Advance to      │
    │ with reason     │     │ next step       │
    └─────────────────┘     └─────────────────┘
              │
              ▼
    ┌─────────────────┐
    │ Workflow state: │
    │ SUCCEEDED or    │
    │ FAILED          │
    │ (per condition) │
    └─────────────────┘
```

### 5.6 Step Graph Model

To support branching, the step model changes from linear to directed acyclic graph (DAG):

```
┌─────────────────────────────────────────────────────────────────┐
│                    Step Graph Model                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Linear (phases 1-15):                                           │
│  A → B → C → D → E                                               │
│                                                                  │
│  DAG (with branching):                                           │
│        ┌→ B1 → B2 ─┐                                            │
│  A → ──┼→ C1 ──────┼─→ E                                        │
│        └→ D1 → D2 ─┘                                            │
│                                                                  │
│  Step eligibility rules:                                         │
│  1. All required outputs available                               │
│  2. All predecessor steps completed                              │
│  3. Step condition evaluates to true                             │
│  4. No termination condition triggered                           │
│  5. Step is on an active branch (if branching)                   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 5.7 Branch Decision Tracking

New table: `maestro_branch_decisions`

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| branch_point_key | String | The step that defines the branch |
| condition_class | String | Condition that was evaluated |
| selected_branches | JSON | Array of branch keys chosen |
| evaluated_at | Timestamp | When decision was made |
| input_summary | JSON, nullable | Summary of data used for decision |

### 5.8 Step Run States (Extended)

| State | Description |
|-------|-------------|
| SKIPPED | Step was skipped due to condition or not on active branch |

Track skip reason in step run:

| Column | Type | Purpose |
|--------|------|---------|
| skip_reason | Enum, nullable | CONDITION_FALSE, NOT_ON_ACTIVE_BRANCH, TERMINATED_EARLY |

### 5.9 Implementation Steps

19.1. **Create Condition Contracts**
   - `StepCondition` interface
   - `BranchCondition` interface
   - `TerminationCondition` interface
   - `ConditionResult` value object

19.2. **Create Branch Definition System**
   - `BranchDefinition` class
   - `BranchPoint` step type
   - Branch key validation
   - Convergence point definition

19.3. **Extend Workflow Definition**
   - Support DAG step graph
   - Branch point registration
   - Convergence point validation
   - Ensure all branches converge or terminate

19.4. **Create Condition Evaluator Service**
   - `ConditionEvaluator` service
   - Dependency injection for condition classes
   - Error handling for evaluation failures

19.5. **Create Branch Decision Persistence**
   - Migration for `maestro_branch_decisions` table
   - `BranchDecisionModel` Eloquent model
   - `BranchDecisionRepository`

19.6. **Modify Step Dispatcher**
   - Evaluate step conditions before dispatch
   - Handle branch point decisions
   - Mark skipped steps with reason

19.7. **Modify Workflow Advancer**
   - Check termination conditions after step completion
   - Handle branch convergence
   - Update eligible step detection for DAG

19.8. **Create Definition Validator Extensions**
   - Validate all branches converge or terminate
   - Validate no orphan steps
   - Validate condition classes exist

19.9. **Create Branch Events**
   - `BranchEvaluated` event
   - `StepSkipped` event
   - `WorkflowTerminatedEarly` event

---

## 6. Phase 20: Polling Steps & Time-Bounded Loops

### 6.1 Objective

Enable steps that:
- Execute repeatedly until a condition is met
- Respect maximum duration and attempt limits
- Support configurable polling intervals
- Allow workflow to continue or terminate based on poll results

### 6.2 Polling Concept

```
┌─────────────────────────────────────────────────────────────────┐
│                    Polling Step Pattern                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Step: wait-for-payment-confirmation                             │
│  Interval: every 5 minutes                                       │
│  Max Duration: 2 days                                            │
│  Max Attempts: 576 (2 days / 5 min)                              │
│                                                                  │
│  Poll 1 ──▶ Check ──▶ Not ready ──▶ Wait for next poll          │
│                                          │                       │
│  Poll 2 ◀────────────────────────────────┘                      │
│     │                                                            │
│     ▼                                                            │
│  Check ──▶ Not ready ──▶ Wait for next poll                     │
│                              │                                   │
│  ...                         │                                   │
│                              │                                   │
│  Poll N ◀────────────────────┘                                  │
│     │                                                            │
│     ▼                                                            │
│  Check ──▶ Ready! ──▶ Step SUCCEEDED ──▶ Continue workflow      │
│                                                                  │
│  OR                                                              │
│                                                                  │
│  Max duration exceeded ──▶ Apply timeout policy                 │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 6.3 Polling Step Definition

```
┌─────────────────────────────────────────────────────────────────┐
│                  Polling Step Definition                         │
├─────────────────────────────────────────────────────────────────┤
│  Key: "wait-for-payment-confirmation"                            │
│  Type: PollingStep                                               │
│                                                                  │
│  Polling Configuration:                                          │
│  ├── Job: CheckPaymentStatusJob::class                          │
│  ├── Interval: 5 minutes                                        │
│  ├── Max Duration: 48 hours (2 days)                            │
│  ├── Max Attempts: 576 (optional, derived from above)           │
│  └── Backoff: Optional exponential (5m → 10m → 20m → ...)       │
│                                                                  │
│  Completion Condition:                                           │
│  └── Determined by PollResult returned from job                 │
│                                                                  │
│  Timeout Policy:                                                 │
│  ├── Action: FailWorkflow | PauseWorkflow | ContinueWithDefault │
│  └── Default Output: PaymentTimeoutOutput (optional)            │
│                                                                  │
│  Produces: PaymentConfirmationOutput                             │
└─────────────────────────────────────────────────────────────────┘
```

### 6.4 Poll Result Contract

```
┌─────────────────────────────────────────────────────────────────┐
│                    Poll Result Contract                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  interface PollResult                                            │
│  {                                                               │
│      isComplete(): bool           // Polling condition satisfied?│
│      shouldContinue(): bool       // Continue if not complete?   │
│      output(): ?StepOutput        // Output to store             │
│      nextInterval(): ?int         // Override next interval (sec)│
│  }                                                               │
│                                                                  │
│  Possible outcomes:                                              │
│  ├── Complete (true, -, output, -)     → Step succeeds          │
│  ├── Continue (false, true, -, null)   → Poll again at interval │
│  ├── Continue (false, true, -, 600)    → Poll again in 10 min   │
│  └── Abort (false, false, -, -)        → Apply failure policy   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 6.5 Poll Scheduling Strategy

**Important:** Laravel's delayed dispatch is not reliable for delays longer than ~15 minutes. Polling uses a scheduler-based approach.

```
┌─────────────────────────────────────────────────────────────────┐
│                 Poll Scheduling Strategy                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Scheduler-Based Approach:                                       │
│  ├── Store next_poll_at timestamp in step_runs table            │
│  ├── Scheduled command runs every minute                        │
│  ├── Finds all step runs WHERE:                                 │
│  │   - status = POLLING                                         │
│  │   - next_poll_at <= NOW()                                    │
│  ├── Dispatches poll jobs immediately (no delay)                │
│  └── Updates next_poll_at after poll completes                  │
│                                                                  │
│  Benefits:                                                       │
│  ├── Reliable for any interval (minutes to days)                │
│  ├── Survives queue driver restarts                             │
│  ├── Queryable (dashboard can show pending polls)               │
│  └── Recoverable (missed polls detected and dispatched)         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 6.6 Polling Execution Flow

```
Poll Job Completes
        │
        ▼
┌─────────────────┐
│ Evaluate        │
│ PollResult      │
└────────┬────────┘
         │
   ┌─────┴─────────────────┐
   │                       │
isComplete()          !isComplete()
 = true                    │
   │         ┌─────────────┴─────────────┐
   │         │                           │
   │   shouldContinue()           !shouldContinue()
   │       = true                        │
   │         │                           ▼
   │         ▼                    ┌─────────────┐
   │   ┌───────────┐              │ Apply abort │
   │   │ Check max │              │ policy      │
   │   │ duration/ │              └─────────────┘
   │   │ attempts  │
   │   └─────┬─────┘
   │         │
   │   ┌─────┴─────┐
   │   │           │
   │ Within     Exceeded
   │ limits        │
   │   │           ▼
   │   │    ┌───────────┐
   │   │    │ Apply     │
   │   │    │ timeout   │
   │   │    │ policy    │
   │   │    └───────────┘
   │   │
   │   ▼
   │ ┌─────────────────────┐
   │ │ Calculate next_poll │
   │ │ = NOW + interval    │
   │ │ (with backoff if    │
   │ │  configured)        │
   │ └─────────────────────┘
   │           │
   │           ▼
   │ ┌─────────────────────┐
   │ │ Update step_run:    │
   │ │ - status = POLLING  │
   │ │ - next_poll_at = X  │
   │ │ - poll_count++      │
   │ └─────────────────────┘
   │
   ▼
┌─────────────────┐
│ Step SUCCEEDED  │
│ Store output    │
│ Continue        │
└─────────────────┘
```

### 6.7 Poll Attempt Tracking

New table: `maestro_poll_attempts`

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| step_run_id | Foreign key | Parent step run |
| attempt_number | Integer | Sequential poll number |
| job_id | Foreign key | Reference to job ledger |
| result_complete | Boolean | Was condition met? |
| result_continue | Boolean | Should polling continue? |
| next_interval_ms | Integer, nullable | Custom interval for next poll |
| executed_at | Timestamp | When poll ran |

### 6.8 Step Run Extensions for Polling

| Column | Type | Purpose |
|--------|------|---------|
| poll_attempt_count | Integer, default 0 | Number of poll attempts |
| next_poll_at | Timestamp, nullable | When next poll is due |
| poll_started_at | Timestamp, nullable | When polling began |

### 6.9 Step Run States (Extended)

| State | Description | Valid Transitions |
|-------|-------------|-------------------|
| POLLING | Step is waiting for next poll interval | RUNNING, SUCCEEDED, FAILED, TIMED_OUT |
| TIMED_OUT | Polling exceeded max duration/attempts | (terminal for step) |

### 6.10 Implementation Steps

20.1. **Create Polling Contracts**
   - `PollingStep` interface
   - `PollResult` interface
   - `PollingConfiguration` value object

20.2. **Create Polling Step Builder**
   - Fluent builder for polling step definition
   - Interval configuration (fixed, exponential backoff)
   - Max duration and max attempts configuration
   - Timeout policy configuration

20.3. **Create Poll Attempt Persistence**
   - Migration for `maestro_poll_attempts` table
   - Extend `maestro_step_runs` with polling columns
   - `PollAttemptModel` Eloquent model

20.4. **Create Polling Step Dispatcher**
   - `PollingStepDispatcher` service
   - Initial poll dispatch (immediate)
   - Set `next_poll_at` timestamp after poll completes
   - Interval calculation with backoff support

20.5. **Create Poll Scheduler Command**
   - `maestro:dispatch-polls` artisan command
   - Query for step runs in POLLING state with `next_poll_at <= now()`
   - Dispatch poll jobs immediately
   - Register in Laravel scheduler to run every minute
   - Handle concurrent execution safely (database locks)

20.6. **Create Poll Result Handler**
   - Evaluate poll result
   - Check duration/attempt limits
   - Schedule next poll or finalize step
   - Apply timeout policy when limits exceeded

20.7. **Extend Step Run State Machine**
   - Add POLLING and TIMED_OUT states
   - Define transitions

20.8. **Create Polling Job Base**
   - `PollingJob` base class (extends OrchestratedJob)
   - Must return `PollResult`
   - Access to previous poll attempt data

20.9. **Create Polling Recovery Command**
   - `maestro:recover-polls` command
   - Find polling steps stuck in RUNNING state (job died)
   - Reset to POLLING state for re-dispatch
   - Detect stale polls (next_poll_at far in past, not dispatched)

20.10. **Create Polling Events**
   - `PollAttempted` event
   - `PollCompleted` event
   - `PollTimedOut` event
   - `PollAborted` event

---

## 7. Phase 21: Automatic Pause Triggers

### 7.1 Objective

Enable workflows to automatically pause after a step completes, waiting for an external action before continuing.

### 7.2 Automatic Pause Concept

```
┌─────────────────────────────────────────────────────────────────┐
│                 Automatic Pause Trigger                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Step: submit-for-approval                                       │
│            │                                                     │
│            ▼                                                     │
│  ┌─────────────────────┐                                        │
│  │ Job completes       │                                        │
│  │ successfully        │                                        │
│  └─────────────────────┘                                        │
│            │                                                     │
│            ▼                                                     │
│  ┌─────────────────────┐                                        │
│  │ Automatic pause     │                                        │
│  │ trigger activates   │                                        │
│  └─────────────────────┘                                        │
│            │                                                     │
│            ▼                                                     │
│  ┌─────────────────────┐     ┌─────────────────────────────┐   │
│  │ Workflow PAUSED     │     │ External systems notified:  │   │
│  │ Awaiting:           │────▶│ - Approval email sent       │   │
│  │ "manager-approval"  │     │ - Slack notification        │   │
│  └─────────────────────┘     └─────────────────────────────┘   │
│            │                                                     │
│            │ Time passes... (hours, days)                        │
│            │                                                     │
│            ▼                                                     │
│  ┌─────────────────────┐                                        │
│  │ External trigger    │                                        │
│  │ received            │                                        │
│  └─────────────────────┘                                        │
│            │                                                     │
│            ▼                                                     │
│  ┌─────────────────────┐                                        │
│  │ Workflow RUNNING    │                                        │
│  │ Continue to next    │                                        │
│  │ step                │                                        │
│  └─────────────────────┘                                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 7.3 Pause Trigger Definition

```
┌─────────────────────────────────────────────────────────────────┐
│                 Pause Trigger Definition                         │
├─────────────────────────────────────────────────────────────────┤
│  Step: "submit-for-approval"                                     │
│  Type: SingleJob                                                 │
│  Job: SubmitApprovalRequestJob::class                           │
│  Produces: ApprovalRequestOutput                                 │
│                                                                  │
│  Pause Trigger (after step completes):                          │
│  ├── Trigger Key: "manager-approval"                            │
│  ├── Timeout: 7 days                                            │
│  ├── Timeout Policy: FailWorkflow | Reminder | AutoApprove      │
│  ├── Payload Schema: ApprovalDecisionPayload (optional)         │
│  └── Resume Condition: ApprovalGrantedCondition (optional)      │
│                                                                  │
│  On Resume:                                                      │
│  ├── Payload stored as: ApprovalDecisionOutput                  │
│  └── Workflow continues to next step                            │
└─────────────────────────────────────────────────────────────────┘
```

### 7.4 Pause Types

| Type | Resume Mechanism | Use Case |
|------|------------------|----------|
| **External Webhook** | HTTP POST to trigger endpoint | Third-party callbacks |
| **Manual Action** | Admin clicks button/API call | Human approval |
| **Scheduled Resume** | Automatic after duration | Cooling-off periods |
| **Conditional Resume** | External system meets condition | Wait for dependency |

### 7.5 Trigger Flow with Payload

```
                    Step Completes
                          │
                          ▼
              ┌───────────────────────┐
              │ Pause trigger         │
              │ configured?           │
              └───────────────────────┘
                          │
              ┌───────────┴───────────┐
              │ YES                NO │
              ▼                       ▼
    ┌─────────────────┐     ┌─────────────────┐
    │ Workflow PAUSED │     │ Continue to     │
    │ - Record trigger│     │ next step       │
    │ - Set timeout   │     └─────────────────┘
    │ - Emit event    │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │ Await trigger   │◀────────────────────────────────┐
    │ or timeout      │                                 │
    └────────┬────────┘                                 │
             │                                          │
    ┌────────┴────────┐                                │
    │                 │                                │
  Trigger          Timeout                             │
  received         reached                             │
    │                 │                                │
    ▼                 ▼                                │
┌─────────┐    ┌─────────┐                            │
│ Validate│    │ Apply   │                            │
│ payload │    │ timeout │                            │
│ (schema)│    │ policy  │                            │
└────┬────┘    └─────────┘                            │
     │                                                │
     ▼                                                │
┌─────────────────┐                                   │
│ Resume          │     NO                            │
│ condition met?  │───────────────────────────────────┘
│ (if configured) │
└────────┬────────┘
         │ YES
         ▼
┌─────────────────┐
│ Store payload   │
│ as step output  │
│ Workflow RUNNING│
└─────────────────┘
```

### 7.6 Pause Trigger Tracking

Extend `maestro_workflows` table:

| Column | Type | Purpose |
|--------|------|---------|
| awaiting_trigger_key | String, nullable | Which trigger is expected |
| trigger_timeout_at | Timestamp, nullable | When trigger times out |
| trigger_registered_at | Timestamp, nullable | When pause started |
| scheduled_resume_at | Timestamp, nullable | When to auto-resume |

New table: `maestro_trigger_payloads`

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| trigger_key | String | Trigger identifier |
| payload_class | String | Payload type class |
| payload | Binary/Text | Serialized payload |
| received_at | Timestamp | When trigger received |
| source_ip | String, nullable | Request source (audit) |
| source_identifier | String, nullable | API key/user (audit) |

### 7.7 Trigger Authentication

```
┌─────────────────────────────────────────────────────────────────┐
│                 Trigger Authentication                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  External triggers must be authenticated:                        │
│                                                                  │
│  Authentication Methods:                                         │
│  ├── HMAC signature (recommended)                               │
│  │   └── Secret per workflow definition                         │
│  ├── Bearer token                                               │
│  │   └── Token generated per workflow instance                  │
│  ├── API key                                                    │
│  │   └── Application-level authentication                       │
│  └── Custom authenticator                                       │
│       └── Implement TriggerAuthenticator interface              │
│                                                                  │
│  Trigger URL format:                                             │
│  POST /maestro/trigger/{workflow_id}/{trigger_key}              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 7.8 Scheduled Resume Feature

```
┌─────────────────────────────────────────────────────────────────┐
│                 Scheduled Resume                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Pause Trigger Definition:                                       │
│  ├── Trigger Key: "cooling-off-period"                          │
│  ├── Auto Resume After: 14 days                                 │
│  └── No External Trigger Expected                               │
│                                                                  │
│  Behavior:                                                       │
│  1. Step completes, workflow pauses                             │
│  2. scheduled_resume_at set to now + 14 days                    │
│  3. Scheduled command checks for due resumes                    │
│  4. Workflow automatically continues after 14 days              │
│                                                                  │
│  Use cases:                                                      │
│  - Legal cooling-off periods                                    │
│  - Mandatory review windows                                     │
│  - Rate limiting / throttling                                   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 7.9 Implementation Steps

21.1. **Extend Step Definition for Pause Triggers**
   - Add `pauseAfter()` method to step builder
   - Trigger key configuration
   - Timeout configuration
   - Payload schema configuration

21.2. **Create Pause Trigger Contracts**
   - `PauseTrigger` interface
   - `TriggerPayload` interface
   - `ResumeCondition` interface
   - `TriggerAuthenticator` interface

21.3. **Extend Workflow Persistence**
   - Add trigger tracking columns to workflows table
   - Migration for `maestro_trigger_payloads` table
   - `TriggerPayloadModel` Eloquent model

21.4. **Create Trigger Handler Service**
   - `TriggerHandler` service
   - Workflow lookup by trigger key
   - Payload validation
   - Resume condition evaluation
   - Authentication verification

21.5. **Extend Step Finalizer**
   - Check for pause trigger after step success
   - Set workflow to PAUSED state
   - Record expected trigger key
   - Set timeout deadline
   - Emit `WorkflowAwaitingTrigger` event

21.6. **Create Trigger Timeout Handler**
   - `maestro:check-trigger-timeouts` artisan command
   - Query workflows WHERE `trigger_timeout_at <= now()` AND state = PAUSED
   - Apply timeout policy (fail or extend)
   - Register in Laravel scheduler to run every minute
   - Emit `TriggerTimedOut` event

21.7. **Create Scheduled Resume Handler**
   - `maestro:process-scheduled-resumes` artisan command
   - Query workflows WHERE `scheduled_resume_at <= now()` AND state = PAUSED
   - Automatically resumes workflow
   - Register in Laravel scheduler to run every minute

21.8. **Create Trigger Authentication System**
   - `HmacTriggerAuthenticator` implementation
   - `TokenTriggerAuthenticator` implementation
   - Configurable per workflow definition

21.9. **Extend External Trigger Controller**
   - Handle payload validation
   - Handle resume conditions
   - Store trigger payload as step output
   - Resume workflow

21.10. **Create Pause Trigger Events**
   - `WorkflowAwaitingTrigger` event
   - `TriggerReceived` event
   - `TriggerValidationFailed` event
   - `TriggerTimedOut` event
   - `WorkflowAutoResumed` event

---

## 8. Persistence Model Extensions

### 8.1 New Tables Summary

| Table | Phase | Purpose |
|-------|-------|---------|
| `maestro_resolution_decisions` | 16 | Track failure resolution decisions |
| `maestro_compensation_runs` | 18 | Track compensation execution |
| `maestro_branch_decisions` | 19 | Audit branch decisions |
| `maestro_poll_attempts` | 20 | Track polling attempts |
| `maestro_trigger_payloads` | 21 | Store trigger payloads |

### 8.2 Extended Columns

**maestro_workflows (extended):**
- `failure_resolution_strategy` (Phase 16)
- `auto_retry_count` (Phase 16)
- `compensation_started_at` (Phase 18)
- `compensated_at` (Phase 18)
- `awaiting_trigger_key` (Phase 21)
- `trigger_timeout_at` (Phase 21)
- `trigger_registered_at` (Phase 21)
- `scheduled_resume_at` (Phase 21)

**maestro_step_runs (extended):**
- `superseded_by_id` (Phase 17)
- `superseded_at` (Phase 17)
- `retry_source` (Phase 17)
- `skip_reason` (Phase 19)
- `branch_key` (Phase 19)
- `poll_attempt_count` (Phase 20)
- `next_poll_at` (Phase 20)
- `poll_started_at` (Phase 20)

### 8.3 Index Strategy

```
┌─────────────────────────────────────────────────────────────────┐
│                 New Indexes                                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  maestro_workflows:                                              │
│  ├── idx_workflows_awaiting_trigger (awaiting_trigger_key)      │
│  ├── idx_workflows_trigger_timeout (state, trigger_timeout_at)  │
│  ├── idx_workflows_scheduled_resume (state, scheduled_resume_at)│
│  └── idx_workflows_state_compensation (state) WHERE COMPENSATING│
│                                                                  │
│  maestro_step_runs:                                              │
│  ├── idx_step_runs_branch (workflow_id, branch_key)             │
│  ├── idx_step_runs_next_poll (status, next_poll_at)             │
│  └── idx_step_runs_superseded (superseded_by_id)                │
│                                                                  │
│  maestro_resolution_decisions:                                   │
│  └── idx_decisions_workflow (workflow_id, created_at)           │
│                                                                  │
│  maestro_compensation_runs:                                      │
│  └── idx_compensations_workflow (workflow_id, status)           │
│                                                                  │
│  maestro_poll_attempts:                                          │
│  └── idx_poll_attempts_step (step_run_id, attempt_number)       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 9. State Machine Updates

### 9.1 Extended Workflow States

```
                                  ┌────────────────┐
                                  │                │
                                  ▼                │
┌─────────┐    ┌─────────┐    ┌─────────┐    ┌───────────┐
│ PENDING │───▶│ RUNNING │───▶│ PAUSED  │───▶│  RUNNING  │
└─────────┘    └─────────┘    └─────────┘    └───────────┘
                    │              │              │
                    │              │              │
                    ▼              ▼              ▼
               ┌─────────────────────────────────────┐
               │             FAILED                  │
               │   (awaiting resolution decision)    │
               └─────────────┬───────────────────────┘
                             │
         ┌───────────────────┼───────────────────┐
         │                   │                   │
         ▼                   ▼                   ▼
   ┌───────────┐      ┌───────────┐       ┌─────────┐
   │ RUNNING   │      │COMPENSATING│      │CANCELLED│
   │ (retry)   │      └─────┬─────┘       └─────────┘
   └───────────┘            │
                    ┌───────┴───────┐
                    │               │
                    ▼               ▼
             ┌───────────┐  ┌─────────────────┐
             │COMPENSATED│  │COMPENSATION_    │
             └───────────┘  │FAILED           │
                            └─────────────────┘

               ┌───────────┐
               │ SUCCEEDED │
               └───────────┘
```

### 9.2 Extended Step Run States

```
┌─────────┐    ┌─────────┐    ┌───────────┐
│ PENDING │───▶│ RUNNING │───▶│ SUCCEEDED │
└─────────┘    └─────────┘    └───────────┘
     │              │
     │              ├───────▶ ┌─────────┐
     │              │         │ POLLING │ (Phase 20)
     │              │         └────┬────┘
     │              │              │
     │              ▼              ▼
     │         ┌─────────┐   ┌───────────┐
     │         │ FAILED  │   │ TIMED_OUT │
     │         └─────────┘   └───────────┘
     │
     ├────────▶ ┌─────────┐
     │          │ SKIPPED │ (Phase 19)
     │          └─────────┘
     │
     └────────▶ ┌────────────┐
               │ SUPERSEDED │ (Phase 17)
               └────────────┘
```

### 9.3 Compensation Run States

```
┌─────────┐    ┌─────────┐    ┌───────────┐
│ PENDING │───▶│ RUNNING │───▶│ SUCCEEDED │
└─────────┘    └─────────┘    └───────────┘
                    │
                    ▼
               ┌─────────┐    ┌─────────┐
               │ FAILED  │───▶│ SKIPPED │
               └─────────┘    └─────────┘
                              (manual skip)
```

---

## 10. Implementation Order & Dependencies

### 10.1 Phase Dependencies

| Phase | Depends On | Can Parallel With |
|-------|------------|-------------------|
| 16 (Failure Resolution) | Phases 1-15 | - |
| 17 (Retry From Step) | Phase 16 | - |
| 18 (Compensation) | Phase 16 | Phase 17 |
| 19 (Branching) | Phases 1-15 | Phases 16, 17, 18 |
| 20 (Polling) | Phases 1-15 | Phases 16-19 |
| 21 (Pause Triggers) | Phases 1-15, 12 | Phases 16-20 |

### 10.2 Implementation Order Summary

| Order | Phase | Focus Area |
|-------|-------|------------|
| 16 | Failure Resolution Strategy | Configurable failure handling |
| 17 | Retry From Step | Re-run from specific step |
| 18 | Compensation System | Saga pattern rollback |
| 19 | Conditional Branching | DAG execution, early termination |
| 20 | Polling Steps | Time-bounded loops |
| 21 | Automatic Pause Triggers | External action waiting |

### 10.3 Post-Phase Steps

Apply the same post-implementation checklist as phases 1-15:

1. **Test Coverage** (≥95%)
2. **Static Analysis** (PHPStan level 9)
3. **Benchmarks** (for new execution paths)
4. **Architecture Tests** (layer boundaries)
5. **Documentation** (PHPDoc for new public APIs)

### 10.4 Critical Path Components

These components require 100% test coverage:

1. **Failure resolution handler** (strategy selection, auto-retry scheduling)
2. **Retry from step service** (output clearing, step superseding)
3. **Compensation executor** (reverse-order execution, retry handling)
4. **Branch condition evaluator** (decision making, DAG traversal)
5. **Poll scheduling logic** (interval calculation, timeout detection)
6. **Trigger handler** (authentication, payload validation)
7. **State transitions** (all new states)

---

## Appendix A: Feature Interaction Matrix

| Feature | Failure Resolution | Retry From Step | Compensation | Branching | Polling | Pause Trigger |
|---------|-------------------|-----------------|--------------|-----------|---------|---------------|
| **Failure Resolution** | - | Retry is one resolution option | Compensate is one resolution option | N/A | N/A | N/A |
| **Retry From Step** | Triggered by resolution | - | Can compensate before retry | Retry respects branch structure | Can retry polling step | Can retry to step before pause |
| **Compensation** | Triggered by resolution | Optional before retry | - | Compensate active branch only | Compensate polling step | N/A |
| **Branching** | N/A | Retry respects branches | Compensate active branch | - | Polling step in branch | Pause after branch |
| **Polling** | N/A | N/A | Can be compensated | Can be in branch | - | Pause after poll complete |
| **Pause Trigger** | N/A | N/A | N/A | Can pause after branch | Can pause after poll | - |

---

## Appendix B: Migration Path

For existing workflows (phases 1-15) without these features:

| Feature | Default Behavior |
|---------|------------------|
| Failure Resolution | AWAIT_DECISION (current FAILED behavior) |
| Retry From Step | Not available until Phase 17 |
| Compensation | No compensation (must define compensation jobs) |
| Branching | Linear execution (current behavior) |
| Polling | Single execution (current behavior) |
| Pause Triggers | Immediate continuation (current behavior) |

All features are opt-in and backward compatible.

---

*End of Advanced Features Plan*
