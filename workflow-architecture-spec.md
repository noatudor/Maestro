# Laravel Workflow Orchestration Package — Architecture Specification

## Document Information

| Property | Value |
|----------|-------|
| Version | 1.0 Draft |
| Status | Architecture Review |
| Scope | General-purpose workflow orchestration for Laravel |

---

## Table of Contents

1. Executive Summary
2. Problem Statement
3. Design Philosophy
4. Core Concepts
5. Persistence Model
6. State Machine Definition
7. Step System Architecture
8. Job Execution Framework
9. Typed Data Passing System
10. Event-Driven Orchestration Model
11. Concurrency Control & Race Conditions
12. Parallelism: Fan-Out and Fan-In
13. Failure Handling & Recovery
14. Retry Model
15. Operational Transparency & Observability
16. Extension Points
17. Constraints & Boundaries
18. Glossary

---

## 1. Executive Summary

This document specifies the architecture for a general-purpose workflow orchestration package for Laravel. The package enables developers to define, execute, monitor, and recover long-running business processes composed of discrete steps, where each step may dispatch one or more queued jobs.

The architecture addresses fundamental limitations in Laravel's native queue system—particularly the absence of "running" state tracking—while providing a framework for building complex, multi-step workflows with full operational visibility.

**Key Capabilities:**

- Long-running workflows spanning hours, days, or weeks
- Explicit pause/resume with external trigger support
- Centralized, readable workflow definitions
- Full integration with Laravel's queue system
- Parallel execution with fan-out/fan-in patterns
- Complete audit trail and operational transparency
- Type-safe data passing between steps
- Comprehensive retry and failure recovery

---

## 2. Problem Statement

### 2.1 Laravel Queue Limitations

Laravel's queue system is designed for independent job execution. It does not natively support:

| Limitation | Impact |
|------------|--------|
| No "running" state | Cannot determine if a job is actively executing vs. still queued |
| No job correlation | Cannot group jobs by business process |
| No step sequencing | Cannot define "Job B runs after Job A completes" |
| No fan-in detection | Cannot detect "all parallel jobs completed" |
| No workflow state | Cannot pause/resume a multi-job process |
| No audit trail | Cannot answer "what happened and when?" |

### 2.2 Existing Solutions Fall Short

Current workflow packages for Laravel typically suffer from:

- Event listener spaghetti: flow logic scattered across dozens of listeners
- Implicit state: workflow progress derived rather than explicit
- JSON blob contexts: untyped, unqueryable workflow data
- No running state: same limitation as native Laravel
- Weak failure recovery: manual intervention required for most failures
- Poor observability: difficult to build operational dashboards

### 2.3 Requirements

The package must provide:

1. **Explicit orchestration**: A single location defines the entire workflow
2. **First-class state tracking**: Every job's lifecycle is recorded
3. **Typed data passing**: No JSON blobs; full IDE support
4. **Operational transparency**: Support for dashboards and admin tools
5. **Failure resilience**: Automatic and manual retry at every level
6. **Extensibility**: Easy to add new step types and behaviors
7. **Laravel-native**: Works with any queue driver; standard Laravel patterns

---

## 3. Design Philosophy

### 3.1 Guiding Principles

**Explicit over implicit**
All state, transitions, and data contracts are explicitly defined and persisted. Nothing is derived or assumed.

**Queryable over convenient**
Data structures prioritize queryability and operational tooling over developer convenience. No JSON blobs for business data.

**Typed over dynamic**
Step inputs, outputs, and dependencies use typed contracts. IDE support (autocomplete, refactoring) is a requirement.

**Auditable over minimal**
Every action creates a record. Storage cost is acceptable; operational blindness is not.

**Recoverable over fragile**
Every failure mode has a recovery path. Manual intervention is always possible but rarely required.

### 3.2 Architectural Style

The package follows an **event-driven orchestration** pattern:

```
┌─────────────────────────────────────────────────────────────────┐
│                     Workflow Definition                         │
│  (Declarative specification of steps, dependencies, policies)   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Orchestration Engine                         │
│  (Stateless evaluator triggered by events, not polling)         │
└─────────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
        ┌──────────┐   ┌──────────┐   ┌──────────┐
        │  Step A  │   │  Step B  │   │  Step C  │
        │ (1 job)  │   │ (N jobs) │   │ (wait)   │
        └──────────┘   └──────────┘   └──────────┘
              │               │               │
              ▼               ▼               ▼
        ┌──────────────────────────────────────────┐
        │           Laravel Queue System           │
        └──────────────────────────────────────────┘
```

There is **no running daemon**. The orchestrator executes in response to:
- Job completion events
- External triggers (webhooks, API calls, user actions)
- Optional scheduled health checks

---

## 4. Core Concepts

### 4.1 Concept Hierarchy

```
Workflow
├── Workflow Definition (static specification)
├── Workflow Instance (runtime execution)
│   ├── Step Run 1
│   │   ├── Job A (attempt 1)
│   │   └── Job A (attempt 2, retry)
│   ├── Step Run 2
│   │   ├── Job B1 (parallel)
│   │   ├── Job B2 (parallel)
│   │   └── Job B3 (parallel)
│   └── Step Run 3
│       └── Job C
└── Workflow Output Store (typed step outputs)
```

### 4.2 Concept Definitions

| Concept | Definition |
|---------|------------|
| **Workflow Definition** | A static specification of steps, their order, dependencies, and policies. Defined in code, versioned with the application. |
| **Workflow Instance** | A single execution of a workflow definition. Has identity, state, and lifecycle. Persisted in database. |
| **Step** | A named unit of work within a workflow. Has eligibility rules, dispatch logic, completion conditions, and failure policies. |
| **Step Run** | A single attempt to execute a step. Multiple step runs may exist for the same step (retries). Each step run is immutable once terminal. |
| **Orchestrated Job** | A Laravel queue job that participates in workflow orchestration. Reports lifecycle events and carries correlation metadata. |
| **Job Record** | A ledger entry tracking a specific job's lifecycle: dispatch time, start time, completion time, status, and failure details. |
| **Workflow Context** | Domain data available to all jobs in a workflow. Loaded from relational tables, presented as typed objects. |
| **Step Output** | Typed data produced by a step, available to subsequent steps. Persisted with explicit schema, not JSON blobs. |

### 4.3 Separation of Concerns

```
┌─────────────────────────────────────────────────────────────────┐
│                    Workflow Definition Layer                    │
│         (What steps exist, their order and policies)            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Orchestration Layer                          │
│    (State management, step advancement, failure handling)       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Job Execution Layer                          │
│         (Job dispatch, lifecycle tracking, retries)             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Data Layer                                   │
│    (Workflow context, step outputs, ledgers, audit trail)       │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Persistence Model

### 5.1 Design Principle: No JSON Blobs

All workflow data is stored in typed, relational structures. This enables:

- SQL queries for operational dashboards
- Database-level constraints and validation
- Efficient indexing and filtering
- IDE support through typed accessors
- Audit and compliance requirements

### 5.2 Core Tables

#### 5.2.1 Workflow Instance Table

Stores the top-level workflow execution state.

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| definition_key | String | Which workflow definition this executes |
| definition_version | String | Version of the definition (for deployment safety) |
| state | Enum | Current workflow state |
| current_step_key | String, nullable | Which step is active |
| paused_at | Timestamp, nullable | When workflow was paused |
| paused_reason | String, nullable | Why workflow is paused |
| failed_at | Timestamp, nullable | When workflow failed |
| failure_code | String, nullable | Machine-readable failure identifier |
| failure_message | Text, nullable | Human-readable failure description |
| succeeded_at | Timestamp, nullable | When workflow completed successfully |
| cancelled_at | Timestamp, nullable | When workflow was cancelled |
| locked_by | String, nullable | Concurrency control |
| locked_at | Timestamp, nullable | Concurrency control |
| created_at | Timestamp | Creation time |
| updated_at | Timestamp | Last modification |

#### 5.2.2 Step Run Table

Records each attempt to execute a step.

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| step_key | String | Which step this executes |
| attempt | Integer | Attempt number (1, 2, 3...) |
| status | Enum | Current step status |
| started_at | Timestamp, nullable | When step began |
| finished_at | Timestamp, nullable | When step reached terminal state |
| failure_code | String, nullable | Machine-readable failure identifier |
| failure_message | Text, nullable | Human-readable failure description |
| failed_job_count | Integer | How many jobs failed |
| total_job_count | Integer | How many jobs were dispatched |
| created_at | Timestamp | Creation time |
| updated_at | Timestamp | Last modification |

#### 5.2.3 Job Ledger Table

Tracks every job dispatched by the workflow system.

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| step_run_id | Foreign key | Parent step run |
| job_uuid | UUID | Unique job identifier (for correlation) |
| job_class | String | Fully qualified class name |
| queue | String | Which queue the job was dispatched to |
| status | Enum | Current job status |
| attempt | Integer | Laravel attempt number |
| dispatched_at | Timestamp | When job was pushed to queue |
| started_at | Timestamp, nullable | When job began executing |
| finished_at | Timestamp, nullable | When job reached terminal state |
| runtime_ms | Integer, nullable | Execution duration |
| failure_class | String, nullable | Exception class name |
| failure_message | Text, nullable | Exception message |
| failure_trace | Text, nullable | Stack trace (truncated) |
| worker_id | String, nullable | Which worker processed the job |
| created_at | Timestamp | Creation time |
| updated_at | Timestamp | Last modification |

#### 5.2.4 Step Output Table

Stores typed outputs produced by steps.

| Column | Type | Purpose |
|--------|------|---------|
| id | Primary key | Identity |
| workflow_id | Foreign key | Parent workflow |
| step_key | String | Which step produced this output |
| output_class | String | Fully qualified class name of the output type |
| payload | Binary/Text | Serialized output object |
| created_at | Timestamp | Creation time |
| updated_at | Timestamp | Last modification |

**Unique constraint:** (workflow_id, output_class)

### 5.3 Workflow Context Storage

The workflow context (domain data available to all jobs) is stored in **application-defined tables**, not in the workflow package tables. The package provides:

- A contract for loading context
- Eager-loading patterns
- Typed accessor patterns

The application implements:

- Domain tables (entities, configurations, assets, etc.)
- Relationships to workflow instance
- Context loader that hydrates typed objects

```
┌─────────────────────────────────────────────────────────────────┐
│                  Application Domain Tables                      │
│  (Your entities: orders, configurations, resources, etc.)       │
│                                                                 │
│  workflow_id FK ←──────────────────────────────────────────────┐│
└─────────────────────────────────────────────────────────────────┘│
                              │                                    │
                              ▼                                    │
┌─────────────────────────────────────────────────────────────────┐│
│                  Workflow Context Loader                        ││
│  (Application-defined, returns typed context object)            ││
└─────────────────────────────────────────────────────────────────┘│
                              │                                    │
                              ▼                                    │
┌─────────────────────────────────────────────────────────────────┐│
│                  Typed Context Object                           ││
│  (Passed to every job; read-only domain data)                   │◀┘
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. State Machine Definition

### 6.1 Workflow States

```
                    ┌──────────────────────────────────────┐
                    │                                      │
                    ▼                                      │
┌─────────┐    ┌─────────┐    ┌─────────┐    ┌───────────┐│
│ PENDING │───▶│ RUNNING │───▶│ PAUSED  │───▶│  RUNNING  ││
└─────────┘    └─────────┘    └─────────┘    └───────────┘│
                    │              │              │        │
                    │              │              │        │
                    ▼              ▼              ▼        │
               ┌─────────┐   ┌─────────┐   ┌───────────┐  │
               │ FAILED  │   │CANCELLED│   │ SUCCEEDED │  │
               └─────────┘   └─────────┘   └───────────┘  │
                    │                                      │
                    │         (manual retry)               │
                    └──────────────────────────────────────┘
```

| State | Description | Valid Transitions |
|-------|-------------|-------------------|
| PENDING | Workflow created but not yet started | RUNNING |
| RUNNING | Workflow is actively executing steps | PAUSED, FAILED, SUCCEEDED |
| PAUSED | Workflow halted, awaiting external trigger or manual resume | RUNNING, CANCELLED |
| SUCCEEDED | All steps completed successfully | (terminal) |
| FAILED | Workflow failed and cannot proceed without intervention | RUNNING (manual retry), CANCELLED |
| CANCELLED | Workflow was explicitly cancelled | (terminal) |

### 6.2 Step Run States

```
┌─────────┐    ┌─────────┐    ┌───────────┐
│ PENDING │───▶│ RUNNING │───▶│ SUCCEEDED │
└─────────┘    └─────────┘    └───────────┘
                    │
                    ▼
               ┌─────────┐
               │ FAILED  │
               └─────────┘
                    │
                    │ (new step run created for retry)
                    ▼
               ┌─────────┐
               │ PENDING │ (new attempt)
               └─────────┘
```

| State | Description | Valid Transitions |
|-------|-------------|-------------------|
| PENDING | Step run created, jobs not yet dispatched | RUNNING |
| RUNNING | Jobs have been dispatched, execution in progress | SUCCEEDED, FAILED |
| SUCCEEDED | All jobs completed successfully | (terminal for this attempt) |
| FAILED | One or more jobs failed beyond retry limits | (terminal for this attempt) |

**Note:** Failed steps can be retried by creating a **new step run** with incremented attempt number. The original step run record is immutable.

### 6.3 Job States

```
┌────────────┐    ┌─────────┐    ┌───────────┐
│ DISPATCHED │───▶│ RUNNING │───▶│ SUCCEEDED │
└────────────┘    └─────────┘    └───────────┘
                       │
                       ▼
                  ┌─────────┐
                  │ FAILED  │
                  └─────────┘
```

| State | Description | Valid Transitions |
|-------|-------------|-------------------|
| DISPATCHED | Job pushed to queue, not yet picked up by worker | RUNNING |
| RUNNING | Worker has started executing the job | SUCCEEDED, FAILED |
| SUCCEEDED | Job completed without exception | (terminal) |
| FAILED | Job threw exception or timed out | (terminal) |

**Note:** Laravel's built-in retry mechanism creates new job attempts. Each attempt is tracked as an update to the same job record (incrementing the attempt counter) until the job either succeeds or exhausts retries.

### 6.4 State Transition Rules

**Workflow transitions are only evaluated:**
- After a job completes (success or failure)
- After an external trigger fires
- After a manual intervention

**Pause and cancel constraints:**
- Workflow can only be paused after current in-flight jobs complete
- Workflow can only be cancelled after current in-flight jobs complete
- No partial cancellation of running jobs

---

## 7. Step System Architecture

### 7.1 Step Definition Components

Each step in a workflow definition specifies:

```
┌─────────────────────────────────────────────────────────────────┐
│                        Step Definition                          │
├─────────────────────────────────────────────────────────────────┤
│  Identity                                                       │
│  ├── Key: unique identifier within workflow                     │
│  └── Display name: human-readable label                         │
├─────────────────────────────────────────────────────────────────┤
│  Dependencies                                                   │
│  ├── Required outputs: which prior step outputs must exist      │
│  └── Predecessor steps: which steps must complete first         │
├─────────────────────────────────────────────────────────────────┤
│  Dispatch Specification                                         │
│  ├── Job class: what job type to dispatch                       │
│  ├── Dispatch mode: single job or fan-out                       │
│  ├── Fan-out iterator: how to generate parallel job payloads    │
│  └── Queue configuration: which queue, priority, delay          │
├─────────────────────────────────────────────────────────────────┤
│  Completion Conditions                                          │
│  ├── Success: all jobs succeeded                                │
│  ├── Partial success: N of M jobs succeeded (configurable)      │
│  └── External trigger: wait for webhook/API/user action         │
├─────────────────────────────────────────────────────────────────┤
│  Output Specification                                           │
│  ├── Output class: typed output this step produces              │
│  └── Merge strategy: how parallel job outputs are combined      │
├─────────────────────────────────────────────────────────────────┤
│  Failure Policy                                                 │
│  ├── Retry limit: max attempts for the step                     │
│  ├── Retry delay: backoff strategy                              │
│  ├── Retry scope: all jobs or only failed jobs                  │
│  └── Failure action: fail workflow, pause workflow, skip step   │
├─────────────────────────────────────────────────────────────────┤
│  Timeout Configuration                                          │
│  ├── Step timeout: max duration for entire step                 │
│  └── Job timeout: max duration for individual job               │
└─────────────────────────────────────────────────────────────────┘
```

### 7.2 Step Types

| Type | Description | Job Count | Completion |
|------|-------------|-----------|------------|
| **Single Job** | Dispatches exactly one job | 1 | Job completes |
| **Fan-Out** | Dispatches N jobs in parallel | N (dynamic) | All jobs complete (fan-in) |
| **External Wait** | Dispatches no jobs; waits for trigger | 0 | External event received |
| **Conditional** | Evaluates condition; may skip | 0-N | Condition evaluated |

### 7.3 Step Execution Flow

```
                    Step Run Created
                          │
                          ▼
                ┌─────────────────────┐
                │ Evaluate Eligibility │
                │ (dependencies met?)  │
                └─────────────────────┘
                          │
              ┌───────────┴───────────┐
              │                       │
              ▼                       ▼
        Dependencies              Dependencies
           Met                      Not Met
              │                       │
              ▼                       ▼
    ┌─────────────────┐        ┌─────────────┐
    │  Dispatch Jobs  │        │    Wait     │
    │ (single or N)   │        │ (blocked)   │
    └─────────────────┘        └─────────────┘
              │
              ▼
    ┌─────────────────┐
    │  Monitor Jobs   │◀──────────────────────┐
    │ (via events)    │                       │
    └─────────────────┘                       │
              │                               │
              ▼                               │
    ┌─────────────────┐                       │
    │  Job Completed  │                       │
    │  (one of N)     │                       │
    └─────────────────┘                       │
              │                               │
              ▼                               │
    ┌─────────────────┐     No               │
    │  All Jobs Done? │──────────────────────┘
    └─────────────────┘
              │ Yes
              ▼
    ┌─────────────────┐
    │ Evaluate Result │
    │ (success/fail)  │
    └─────────────────┘
              │
      ┌───────┴───────┐
      │               │
      ▼               ▼
  SUCCEEDED        FAILED
      │               │
      ▼               ▼
  Advance to     Apply Failure
  Next Step         Policy
```

### 7.4 Step Registry

The package maintains a registry of step definitions for each workflow:

```
┌─────────────────────────────────────────────────────────────────┐
│                      Workflow Definition                        │
├─────────────────────────────────────────────────────────────────┤
│  Definition Key: "order-fulfillment"                            │
│  Version: "2.1.0"                                               │
├─────────────────────────────────────────────────────────────────┤
│  Steps (ordered):                                               │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 1. validate-order                                          │ │
│  │    Requires: (none)                                        │ │
│  │    Produces: OrderValidatedOutput                          │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ 2. reserve-inventory                                       │ │
│  │    Requires: OrderValidatedOutput                          │ │
│  │    Produces: InventoryReservedOutput                       │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ 3. process-payment                                         │ │
│  │    Requires: OrderValidatedOutput, InventoryReservedOutput │ │
│  │    Produces: PaymentProcessedOutput                        │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ 4. ship-items (fan-out)                                    │ │
│  │    Requires: InventoryReservedOutput                       │ │
│  │    Produces: ItemsShippedOutput (mergeable)                │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ 5. send-confirmation                                       │ │
│  │    Requires: PaymentProcessedOutput, ItemsShippedOutput    │ │
│  │    Produces: (none)                                        │ │
│  └────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

---

## 8. Job Execution Framework

### 8.1 Why Custom Job Tracking

Laravel's queue system does not persist:

| Missing Information | Impact |
|---------------------|--------|
| Job start time | Cannot calculate duration or detect zombies |
| Running state | Cannot distinguish "queued" from "executing" |
| Workflow correlation | Cannot group jobs by business process |
| Step correlation | Cannot aggregate job results for fan-in |
| Detailed failure info | Limited to failed_jobs table (optional) |

The package introduces an **Orchestrated Job** base type that all workflow jobs must extend.

### 8.2 Orchestrated Job Responsibilities

```
┌─────────────────────────────────────────────────────────────────┐
│                     Orchestrated Job                            │
├─────────────────────────────────────────────────────────────────┤
│  Carries Metadata                                               │
│  ├── Workflow ID                                                │
│  ├── Step Run ID                                                │
│  ├── Job UUID (for correlation)                                 │
│  └── Dispatch timestamp                                         │
├─────────────────────────────────────────────────────────────────┤
│  Reports Lifecycle Events                                       │
│  ├── On dispatch: creates job ledger entry (DISPATCHED)         │
│  ├── On start: updates to RUNNING, records start time           │
│  ├── On success: updates to SUCCEEDED, records completion       │
│  └── On failure: updates to FAILED, records exception details   │
├─────────────────────────────────────────────────────────────────┤
│  Provides Execution Context                                     │
│  ├── Workflow context (typed domain data)                       │
│  ├── Step output store (read previous outputs)                  │
│  └── Step output writer (write current output)                  │
├─────────────────────────────────────────────────────────────────┤
│  Supports Idempotency                                           │
│  ├── Idempotency key generation                                 │
│  └── Duplicate execution detection                              │
└─────────────────────────────────────────────────────────────────┘
```

### 8.3 Job Lifecycle Sequence

```
┌──────────┐      ┌──────────┐      ┌──────────┐      ┌──────────┐
│ Dispatch │─────▶│  Queue   │─────▶│  Worker  │─────▶│ Execute  │
└──────────┘      └──────────┘      └──────────┘      └──────────┘
     │                                   │                  │
     ▼                                   ▼                  ▼
┌──────────┐                       ┌──────────┐      ┌──────────┐
│  Create  │                       │  Update  │      │  Update  │
│  Ledger  │                       │  Ledger  │      │  Ledger  │
│  Entry   │                       │ RUNNING  │      │ TERMINAL │
│DISPATCHED│                       │ + start  │      │ + result │
└──────────┘                       └──────────┘      └──────────┘
                                                          │
                                                          ▼
                                                    ┌──────────┐
                                                    │  Emit    │
                                                    │  Event   │
                                                    │(triggers │
                                                    │ advancer)│
                                                    └──────────┘
```

### 8.4 Lifecycle Hook Implementation Options

The package can implement lifecycle tracking via:

| Mechanism | Pros | Cons |
|-----------|------|------|
| Job middleware | Clean separation; Laravel-native | Requires middleware registration |
| Queue event listeners | Centralized; no job modification | Events may not fire in all failure modes |
| Base class methods | Guaranteed execution | Requires inheritance discipline |
| Trait with hooks | Flexible; composable | Developer must call hooks |

**Recommended:** Combination of base class (for correlation data) and queue event listeners (for lifecycle tracking).

### 8.5 Zombie Job Detection

A "zombie" job is one marked RUNNING but whose worker has died. Detection strategies:

| Strategy | Description | Trade-offs |
|----------|-------------|------------|
| **Heartbeat** | Jobs periodically update a timestamp; reaper checks for stale heartbeats | Accurate but adds overhead; complex in distributed systems |
| **Timeout-based** | Each job has max_runtime; reaper checks started_at + max_runtime < now | Simple; may have false positives if job legitimately runs long |
| **Worker death detection** | Monitor worker processes directly | Infrastructure-dependent; doesn't catch all cases |

**Recommended:** Timeout-based detection with conservative timeouts. Jobs can specify their expected maximum runtime; a scheduled reaper marks stale jobs as failed.

---

## 9. Typed Data Passing System

### 9.1 Design Goals

| Goal | Rationale |
|------|-----------|
| No string keys | String keys cause typos, break refactoring, lack IDE support |
| Explicit contracts | Each step declares what it requires and produces |
| IDE autocomplete | Developers can discover available data via autocomplete |
| Compile-time validation | Missing dependencies caught before runtime |
| Queryable storage | Outputs can be examined via database queries |

### 9.2 Two-Tier Data Model

```
┌─────────────────────────────────────────────────────────────────┐
│                 Tier 1: Workflow Context                        │
│        (Domain data; entire workflow lifetime)                  │
├─────────────────────────────────────────────────────────────────┤
│  • Stored in application domain tables                          │
│  • Loaded once per job via context loader                       │
│  • Read-only during job execution                               │
│  • Typed via application-defined context class                  │
│  • Examples: order details, configuration, resources            │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                 Tier 2: Step Outputs                            │
│        (Inter-step data; passed forward)                        │
├─────────────────────────────────────────────────────────────────┤
│  • Stored in step output table (serialized typed objects)       │
│  • Written by jobs, read by subsequent steps                    │
│  • Typed via output contract classes                            │
│  • Validated at workflow definition time                        │
│  • Examples: created entity IDs, computed values, status flags  │
└─────────────────────────────────────────────────────────────────┘
```

### 9.3 Step Output Contract Pattern

Each step declares its contract via a typed output class:

```
┌─────────────────────────────────────────────────────────────────┐
│                    Step Output Contract                         │
├─────────────────────────────────────────────────────────────────┤
│  Implements: StepOutput interface                               │
│  ├── stepKey(): string (for database storage)                   │
│  └── (typed public properties)                                  │
├─────────────────────────────────────────────────────────────────┤
│  Optional: MergeableOutput interface (for fan-out)              │
│  └── mergeWith(self): self (combines parallel job outputs)      │
├─────────────────────────────────────────────────────────────────┤
│  Benefits:                                                      │
│  ├── IDE autocomplete on properties                             │
│  ├── Refactoring tools work correctly                           │
│  ├── Type errors caught at development time                     │
│  └── Single source of truth for step key                        │
└─────────────────────────────────────────────────────────────────┘
```

### 9.4 Step Dependency Declaration

Each step declares its dependencies as class references:

```
┌─────────────────────────────────────────────────────────────────┐
│                    Step Definition                              │
├─────────────────────────────────────────────────────────────────┤
│  key(): string                                                  │
│      "process-payment"                                          │
├─────────────────────────────────────────────────────────────────┤
│  requires(): array<class-string<StepOutput>>                    │
│      [OrderValidatedOutput::class, InventoryReservedOutput::class]
├─────────────────────────────────────────────────────────────────┤
│  produces(): ?class-string<StepOutput>                          │
│      PaymentProcessedOutput::class                              │
└─────────────────────────────────────────────────────────────────┘
```

### 9.5 Output Store Operations

```
┌─────────────────────────────────────────────────────────────────┐
│                  Workflow Output Store                          │
├─────────────────────────────────────────────────────────────────┤
│  read(OutputClass): OutputClass                                 │
│      Returns typed output; throws if missing                    │
├─────────────────────────────────────────────────────────────────┤
│  has(OutputClass): bool                                         │
│      Checks if output exists                                    │
├─────────────────────────────────────────────────────────────────┤
│  write(StepOutput): void                                        │
│      Persists output; handles merge for MergeableOutput         │
└─────────────────────────────────────────────────────────────────┘
```

### 9.6 Fan-Out Output Merging

When multiple parallel jobs each produce output, the store automatically merges them:

```
┌─────────┐  ┌─────────┐  ┌─────────┐
│  Job 1  │  │  Job 2  │  │  Job 3  │
│ Output  │  │ Output  │  │ Output  │
│ {a: 1}  │  │ {b: 2}  │  │ {c: 3}  │
└────┬────┘  └────┬────┘  └────┬────┘
     │            │            │
     └──────┬─────┴─────┬──────┘
            │           │
            ▼           ▼
     ┌──────────────────────────┐
     │    MergeableOutput       │
     │    mergeWith() called    │
     │    repeatedly            │
     └──────────────────────────┘
            │
            ▼
     ┌──────────────────────────┐
     │    Final Merged Output   │
     │    {a: 1, b: 2, c: 3}    │
     └──────────────────────────┘
```

### 9.7 Workflow Definition Validation

At workflow boot (or during CI), the validator checks:

```
┌─────────────────────────────────────────────────────────────────┐
│              Workflow Definition Validator                      │
├─────────────────────────────────────────────────────────────────┤
│  For each step in order:                                        │
│  1. Check requires() against available outputs                  │
│     └── If missing: throw InvalidWorkflowDefinition             │
│  2. Add produces() to available outputs                         │
│  3. Continue to next step                                       │
├─────────────────────────────────────────────────────────────────┤
│  Catches at development time:                                   │
│  ├── Step requires output that no prior step produces           │
│  ├── Circular dependencies (if graph-based ordering)            │
│  └── Missing output declarations                                │
└─────────────────────────────────────────────────────────────────┘
```

### 9.8 Type Safety Benefits Summary

| Without Typed Outputs | With Typed Outputs |
|-----------------------|--------------------|
| `$outputs->get('campaign.create', 'campaign_id')` | `$outputs->read(CampaignCreatedOutput::class)->campaignId` |
| String typos cause runtime errors | Class names validated by IDE/compiler |
| No autocomplete | Full autocomplete on properties |
| Renaming requires find/replace | Refactoring tools work automatically |
| "What data is available?" requires docs | `requires()` method is authoritative |
| Missing data discovered at runtime | Validator catches at boot |

---

## 10. Event-Driven Orchestration Model

### 10.1 No Running Daemon

The orchestrator is **not** a continuously running process. It is stateless code that executes in response to triggers.

```
┌─────────────────────────────────────────────────────────────────┐
│                   Traditional Orchestrator                      │
│              (NOT the approach used here)                       │
├─────────────────────────────────────────────────────────────────┤
│  while (true) {                                                 │
│      foreach (activeWorkflows as workflow) {                    │
│          checkJobStatus(workflow)                               │
│          advanceIfReady(workflow)                               │
│      }                                                          │
│      sleep(pollInterval)                                        │
│  }                                                              │
├─────────────────────────────────────────────────────────────────┤
│  Problems:                                                      │
│  ├── Constant compute even when nothing happening               │
│  ├── Poll interval creates latency                              │
│  ├── Single point of failure                                    │
│  └── Scaling requires complex coordination                      │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                   Event-Driven Orchestrator                     │
│                   (The approach used here)                      │
├─────────────────────────────────────────────────────────────────┤
│  on JobCompleted(event):                                        │
│      evaluate(event.workflowId)                                 │
│                                                                 │
│  on ExternalTrigger(event):                                     │
│      evaluate(event.workflowId)                                 │
│                                                                 │
│  on ManualResume(event):                                        │
│      evaluate(event.workflowId)                                 │
├─────────────────────────────────────────────────────────────────┤
│  Benefits:                                                      │
│  ├── Zero compute when idle                                     │
│  ├── Immediate response to events                               │
│  ├── No single point of failure                                 │
│  └── Scales naturally with queue workers                        │
└─────────────────────────────────────────────────────────────────┘
```

### 10.2 Trigger Types

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Job Completion │     │ External Trigger│     │   Scheduled     │
│     Trigger     │     │    Trigger      │     │    Trigger      │
└────────┬────────┘     └────────┬────────┘     └────────┬────────┘
         │                       │                       │
         │ Queue event           │ Webhook/API           │ Cron
         │ listener              │ controller            │ command
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
                                 ▼
                    ┌────────────────────────┐
                    │   Workflow Advancer    │
                    │   evaluate(workflowId) │
                    └────────────────────────┘
```

| Trigger Type | When It Fires | Example |
|--------------|---------------|---------|
| Job completion | Queue worker finishes a job | Job succeeds or fails |
| External trigger | External system calls API | Webhook, approval button, third-party callback |
| Manual trigger | Admin/user initiates action | Retry button, resume button |
| Scheduled trigger | Cron job runs | Timeout checker, stale workflow detector |

### 10.3 Workflow Advancer

The advancer is a stateless function that evaluates a workflow and takes appropriate action:

```
┌─────────────────────────────────────────────────────────────────┐
│                     Workflow Advancer                           │
│                  evaluate(workflowId)                           │
├─────────────────────────────────────────────────────────────────┤
│  1. Acquire lock on workflow (prevents concurrent evaluation)   │
│  2. Load workflow state                                         │
│  3. If terminal or paused: release lock and return              │
│  4. Check current step status                                   │
│     ├── If RUNNING and jobs incomplete: return (wait for more)  │
│     └── If RUNNING and jobs complete: finalize step status      │
│  5. If step FAILED: apply failure policy                        │
│  6. If step SUCCEEDED: determine next step                      │
│     ├── If no more steps: mark workflow SUCCEEDED               │
│     ├── If next step requires trigger: mark workflow PAUSED     │
│     └── Otherwise: dispatch next step                           │
│  7. Release lock                                                │
└─────────────────────────────────────────────────────────────────┘
```

### 10.4 Long Pause Efficiency

Workflows that wait for external events (approvals, webhooks, human review) consume **zero compute** while waiting:

```
Timeline:
─────────────────────────────────────────────────────────────────▶

Day 1:                    Day 3:                    Day 3 + 1 min:
┌──────────────┐          ┌──────────────┐          ┌──────────────┐
│ Step "await- │          │ External     │          │ Advancer     │
│ approval"    │          │ approval     │          │ runs once,   │
│ completes,   │          │ received via │          │ dispatches   │
│ workflow     │          │ webhook      │          │ next step    │
│ PAUSED       │          │              │          │              │
└──────────────┘          └──────────────┘          └──────────────┘
       │                         │                         │
       │◀── Zero compute ───────▶│                         │
       │    (2 days)             │                         │
```

### 10.5 Optional Scheduled Triggers

Scheduled triggers are only needed for:

| Purpose | Frequency | Action |
|---------|-----------|--------|
| Zombie detection | Every few minutes | Mark stale RUNNING jobs as FAILED |
| Timeout enforcement | Hourly | Fail workflows exceeding max duration |
| Stuck workflow alerts | Daily | Notify ops of workflows in PAUSED > N days |
| Cleanup/archival | Weekly | Archive old completed workflows |

These do **not** poll for advancement; they handle edge cases only.

---

## 11. Concurrency Control & Race Conditions

### 11.1 Critical Race: Fan-In Completion

**Scenario:** A step dispatches 3 parallel jobs. Jobs 2 and 3 complete at the exact same moment on different workers. Both workers check "are all jobs done?" and both see "yes."

**Risk:** Step is finalized twice, next step is dispatched twice.

```
Worker A (Job 2 completes)          Worker B (Job 3 completes)
─────────────────────────           ─────────────────────────
Mark Job 2 SUCCEEDED                Mark Job 3 SUCCEEDED
Check: all jobs done? YES           Check: all jobs done? YES
Finalize step as SUCCEEDED          Finalize step as SUCCEEDED  ← DUPLICATE
Dispatch next step                  Dispatch next step          ← DUPLICATE
```

**Solution: Atomic Conditional Update**

```
Instead of:
  1. Check if all jobs done
  2. If yes, update step status

Use:
  1. Atomic: UPDATE step SET status = SUCCEEDED 
            WHERE id = X AND status = RUNNING
  2. Check affected rows
  3. If 0 rows affected: another worker already did it
  4. If 1 row affected: proceed with next step dispatch
```

This ensures exactly one worker "wins" the race.

### 11.2 Workflow-Level Locking

When evaluating a workflow, the advancer must prevent concurrent evaluation:

| Approach | Implementation | Trade-offs |
|----------|----------------|------------|
| Database row lock | SELECT ... FOR UPDATE | Simple; may cause contention |
| Advisory lock | Database-specific locking | Lower contention; more complex |
| Optimistic lock | Version column check | No blocking; may need retry |
| Distributed lock | Redis/external lock manager | Works across multiple databases |

**Recommended:** Database row lock with short hold time. The advancer should complete quickly (milliseconds), so contention is minimal.

### 11.3 Job Dispatch Idempotency

Jobs may be dispatched more than once due to:
- Queue driver retries
- Network issues during dispatch
- Worker failures during processing

**Protection mechanisms:**

| Mechanism | Level | Description |
|-----------|-------|-------------|
| Job UUID | Job ledger | Prevents duplicate ledger entries |
| Idempotency key | External API | Prevents duplicate external operations |
| Deduplication check | Job start | Skip if already completed |

---

## 12. Parallelism: Fan-Out and Fan-In

### 12.1 Fan-Out Pattern

A single step dispatches multiple jobs that execute in parallel:

```
                    ┌─────────────────┐
                    │  Step: process  │
                    │    items        │
                    └────────┬────────┘
                             │
             ┌───────────────┼───────────────┐
             │               │               │
             ▼               ▼               ▼
      ┌───────────┐   ┌───────────┐   ┌───────────┐
      │  Job 1    │   │  Job 2    │   │  Job 3    │
      │ (item A)  │   │ (item B)  │   │ (item C)  │
      └───────────┘   └───────────┘   └───────────┘
```

**Fan-out specification:**

| Component | Purpose |
|-----------|---------|
| Iterator | Generates the list of items to process |
| Job factory | Creates job instance for each item |
| Parallelism limit | Optional max concurrent jobs |
| Failure tolerance | How many failures before step fails |

### 12.2 Fan-In Pattern

The step waits for all parallel jobs to complete before advancing:

```
      ┌───────────┐   ┌───────────┐   ┌───────────┐
      │  Job 1    │   │  Job 2    │   │  Job 3    │
      │ SUCCEEDED │   │ SUCCEEDED │   │ SUCCEEDED │
      └─────┬─────┘   └─────┬─────┘   └─────┬─────┘
            │               │               │
            └───────────────┼───────────────┘
                            │
                            ▼
                   ┌─────────────────┐
                   │  Fan-In Check   │
                   │ All jobs done?  │
                   └────────┬────────┘
                            │ YES
                            ▼
                   ┌─────────────────┐
                   │ Merge Outputs   │
                   │ Advance Step    │
                   └─────────────────┘
```

### 12.3 Partial Success Handling

Some workflows can tolerate partial failures in fan-out steps:

| Policy | Behavior |
|--------|----------|
| All must succeed | Step fails if any job fails |
| Majority must succeed | Step succeeds if > 50% succeed |
| N must succeed | Step succeeds if N jobs succeed |
| Best effort | Step succeeds if any job succeeds |

The policy is specified in the step definition.

### 12.4 Fan-Out Output Aggregation

Parallel jobs each produce output. The outputs must be merged:

```
Job 1 Output:              Job 2 Output:              Job 3 Output:
┌──────────────────┐       ┌──────────────────┐       ┌──────────────────┐
│ ProcessedItem    │       │ ProcessedItem    │       │ ProcessedItem    │
│ itemId: 1        │       │ itemId: 2        │       │ itemId: 3        │
│ result: "ok"     │       │ result: "ok"     │       │ result: "error"  │
└──────────────────┘       └──────────────────┘       └──────────────────┘
         │                          │                          │
         └──────────────────────────┼──────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │ Merged Output (via mergeWith) │
                    │ items: [                      │
                    │   {id: 1, result: "ok"},      │
                    │   {id: 2, result: "ok"},      │
                    │   {id: 3, result: "error"}    │
                    │ ]                             │
                    └───────────────────────────────┘
```

---

## 13. Failure Handling & Recovery

### 13.1 Failure Levels

```
┌─────────────────────────────────────────────────────────────────┐
│                     Failure Hierarchy                           │
├─────────────────────────────────────────────────────────────────┤
│  Level 1: Job Failure                                           │
│  ├── Cause: Exception, timeout, worker death                    │
│  ├── Scope: Single job execution                                │
│  └── Recovery: Laravel retry, then step-level policy            │
├─────────────────────────────────────────────────────────────────┤
│  Level 2: Step Failure                                          │
│  ├── Cause: Job failures exceed tolerance                       │
│  ├── Scope: Single step execution                               │
│  └── Recovery: Step retry (new step run) or workflow failure    │
├─────────────────────────────────────────────────────────────────┤
│  Level 3: Workflow Failure                                      │
│  ├── Cause: Step failure with "fail workflow" policy            │
│  ├── Scope: Entire workflow                                     │
│  └── Recovery: Manual intervention, workflow retry              │
└─────────────────────────────────────────────────────────────────┘
```

### 13.2 Job Failure Handling

When a job fails:

```
┌──────────────────┐
│   Job Throws     │
│   Exception      │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐     YES    ┌──────────────────┐
│  Laravel Retry   │───────────▶│   Re-queue Job   │
│  Available?      │            │  (same job UUID) │
└────────┬─────────┘            └──────────────────┘
         │ NO
         ▼
┌──────────────────┐
│  Update Job      │
│  Ledger: FAILED  │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  Emit JobFailed  │
│  Event           │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  Trigger         │
│  Advancer        │
└──────────────────┘
```

### 13.3 Step Failure Policies

| Policy | Behavior | Use Case |
|--------|----------|----------|
| Fail workflow | Mark workflow as FAILED immediately | Critical steps |
| Pause workflow | Mark workflow as PAUSED for intervention | Semi-critical steps |
| Retry step | Create new step run, re-dispatch jobs | Transient failures |
| Skip step | Mark step FAILED but continue workflow | Optional steps |
| Continue with partial | Mark step SUCCEEDED if threshold met | Tolerant steps |

### 13.4 Step Retry Strategies

| Strategy | Behavior |
|----------|----------|
| Retry all jobs | Re-dispatch every job regardless of prior status |
| Retry failed only | Re-dispatch only jobs that failed |
| Retry with backoff | Add delay between step retry attempts |
| Retry with jitter | Randomize delay to prevent thundering herd |

### 13.5 Workflow Recovery Options

| Scenario | Recovery Action |
|----------|-----------------|
| Step failed, retries exhausted | Manual: Retry step or cancel workflow |
| Workflow failed | Manual: Fix issue, retry from failed step |
| Workflow stuck (PAUSED too long) | Manual: Resume or cancel |
| Zombie jobs detected | Automatic: Mark failed, trigger advancer |

---

## 14. Retry Model

### 14.1 Retry Philosophy

Every failure should have a path to recovery:

```
┌─────────────────────────────────────────────────────────────────┐
│                      Retry Principle                            │
├─────────────────────────────────────────────────────────────────┤
│  Automatic retry: For transient, recoverable failures           │
│  ├── Network timeouts                                           │
│  ├── Rate limits (with backoff)                                 │
│  ├── Temporary service unavailability                           │
│  └── Deadlocks and lock conflicts                               │
├─────────────────────────────────────────────────────────────────┤
│  Manual retry: For failures requiring investigation             │
│  ├── Business logic errors                                      │
│  ├── Data validation failures                                   │
│  ├── External service permanent errors                          │
│  └── Unknown/unexpected exceptions                              │
└─────────────────────────────────────────────────────────────────┘
```

### 14.2 Retry Levels

| Level | Trigger | Who Initiates | Record Keeping |
|-------|---------|---------------|----------------|
| Job retry | Job fails, attempts remaining | Laravel queue | Job ledger attempt counter |
| Step retry | Step fails, retries allowed | Advancer or manual | New step run record |
| Workflow retry | Workflow failed | Manual only | Resume from failed step |

### 14.3 Step Retry Mechanics

A step retry creates a **new step run record**:

```
Original Step Run:
┌─────────────────────────────────────────────────────────────────┐
│  step_key: "process-payment"                                    │
│  attempt: 1                                                     │
│  status: FAILED                                                 │
│  jobs: [Job A (FAILED), Job B (SUCCEEDED)]                      │
└─────────────────────────────────────────────────────────────────┘

After Retry (new record created):
┌─────────────────────────────────────────────────────────────────┐
│  step_key: "process-payment"                                    │
│  attempt: 2                                                     │
│  status: RUNNING                                                │
│  jobs: [Job A' (DISPATCHED)]  ← Only failed job retried         │
└─────────────────────────────────────────────────────────────────┘
```

The original step run is **immutable**—it serves as an audit record.

### 14.4 Idempotency Requirements

Retry safety requires idempotent operations:

| Operation Type | Idempotency Strategy |
|----------------|---------------------|
| Create external resource | Use idempotency key; check for existing |
| Update external resource | Use conditional update; verify state |
| Send notification | Track sent status; skip if already sent |
| Financial transaction | Use transaction ID; verify not duplicate |
| Data transformation | Verify output matches expected; overwrite |

---

## 15. Operational Transparency & Observability

### 15.1 Queryable State

All workflow state is stored in queryable database tables:

| Question | Query Target |
|----------|--------------|
| How many workflows are running? | Workflow table, state = RUNNING |
| Which step is workflow X on? | Workflow table, current_step_key |
| Why did workflow X fail? | Workflow table, failure_* columns |
| What jobs are running for step Y? | Job ledger, step_run_id + status |
| How long did job Z take? | Job ledger, runtime_ms |
| What's the retry count for step Y? | Step run table, attempt column |

### 15.2 Dashboard Capabilities

The persistence model supports building dashboards that show:

```
┌─────────────────────────────────────────────────────────────────┐
│                    Workflow Dashboard                           │
├─────────────────────────────────────────────────────────────────┤
│  Summary Statistics                                             │
│  ├── Active workflows: 47                                       │
│  ├── Paused (awaiting action): 12                               │
│  ├── Failed (needs attention): 3                                │
│  └── Completed today: 156                                       │
├─────────────────────────────────────────────────────────────────┤
│  Workflow Detail View                                           │
│  ├── Timeline: step progression with timestamps                 │
│  ├── Current status: which step, what's happening               │
│  ├── Job list: all jobs with status, duration, errors           │
│  └── Actions: retry, resume, cancel buttons                     │
├─────────────────────────────────────────────────────────────────┤
│  Alerting Hooks                                                 │
│  ├── Workflow failed                                            │
│  ├── Workflow paused > N hours                                  │
│  ├── Step retry count > threshold                               │
│  └── Job duration > expected                                    │
└─────────────────────────────────────────────────────────────────┘
```

### 15.3 Audit Trail

Every state change is recorded:

| Event | Recorded Data |
|-------|---------------|
| Workflow created | Timestamp, definition, initial context |
| Workflow state change | From state, to state, timestamp, reason |
| Step run created | Timestamp, step key, attempt number |
| Step run completed | Timestamp, status, job summary |
| Job dispatched | Timestamp, job class, queue, payload hash |
| Job started | Timestamp, worker ID |
| Job completed | Timestamp, status, duration, error details |

### 15.4 Debugging Support

When investigating issues:

```
Timeline View for Workflow #12345:
──────────────────────────────────────────────────────────────────

10:00:00  Workflow created (definition: order-fulfillment v2.1)
10:00:01  Step "validate-order" started (attempt 1)
10:00:01  Job dispatched: ValidateOrderJob [uuid: abc-123]
10:00:02  Job started: ValidateOrderJob (worker: queue-1)
10:00:03  Job completed: ValidateOrderJob (SUCCEEDED, 1.2s)
10:00:03  Step "validate-order" completed (SUCCEEDED)
10:00:03  Step "reserve-inventory" started (attempt 1)
10:00:03  Job dispatched: ReserveInventoryJob [uuid: def-456]
10:00:05  Job started: ReserveInventoryJob (worker: queue-2)
10:00:08  Job failed: ReserveInventoryJob (InventoryException: Item out of stock)
10:00:08  Step "reserve-inventory" failed (1 of 1 jobs failed)
10:00:08  Workflow state: FAILED (step failure with fail-workflow policy)
```

---

## 16. Extension Points

### 16.1 Customization Opportunities

The package provides extension points for application-specific behavior:

| Extension Point | Purpose | Implementation |
|-----------------|---------|----------------|
| Context loader | Load application domain data | Implement interface |
| Step types | Custom step behaviors | Extend base step class |
| Failure policies | Custom failure handling | Implement policy interface |
| Output serialization | Custom persistence format | Implement serializer |
| Event hooks | React to workflow events | Laravel event listeners |
| Retry strategies | Custom backoff algorithms | Implement strategy interface |

### 16.2 Event Hooks

The package emits Laravel events at key points:

| Event | When Fired | Typical Use |
|-------|------------|-------------|
| WorkflowCreated | Workflow instance created | Logging, metrics |
| WorkflowStarted | First step begins | Notifications |
| WorkflowPaused | Workflow enters PAUSED state | Alerts, reminders |
| WorkflowResumed | Workflow exits PAUSED state | Logging |
| WorkflowSucceeded | Workflow completes successfully | Notifications, cleanup |
| WorkflowFailed | Workflow enters FAILED state | Alerts, escalation |
| StepStarted | Step begins execution | Logging, metrics |
| StepSucceeded | Step completes successfully | Logging |
| StepFailed | Step fails | Alerts |
| JobDispatched | Job pushed to queue | Metrics |
| JobStarted | Job begins execution | Metrics |
| JobSucceeded | Job completes successfully | Metrics |
| JobFailed | Job fails | Logging, alerts |

### 16.3 Middleware Support

Jobs support middleware for cross-cutting concerns:

| Concern | Middleware Approach |
|---------|---------------------|
| Rate limiting | Check rate limit before execution |
| Circuit breaker | Skip if external service is down |
| Tenant isolation | Set tenant context for multi-tenant apps |
| Logging | Structured logging for all jobs |
| Metrics | Duration, success rate tracking |

---

## 17. Constraints & Boundaries

### 17.1 What This Package Does

- Orchestrates multi-step, long-running workflows
- Tracks job lifecycle with first-class running state
- Provides typed data passing between steps
- Supports parallel execution with fan-out/fan-in
- Enables retry and recovery at every level
- Offers full operational transparency

### 17.2 What This Package Does Not Do

| Out of Scope | Rationale |
|--------------|-----------|
| Visual workflow designer | UI tooling is application-specific |
| Distributed transactions | Saga pattern should use this package, not be built into it |
| External service adapters | Application implements specific integrations |
| Cron job scheduling | Use Laravel's native scheduler |
| Real-time streaming | Different architectural pattern |
| Complex branching logic | Keep step graph simple; use conditional steps |

### 17.3 Constraints

| Constraint | Rationale |
|------------|-----------|
| Jobs must extend base class | Required for lifecycle tracking |
| Outputs must implement interface | Required for type safety |
| Single database | Simplifies transactions and locking |
| No partial job cancellation | Simplifies state management |
| Pause/cancel only after job completion | Avoids complex partial-execution states |

### 17.4 Scalability Considerations

| Component | Scaling Approach |
|-----------|------------------|
| Workflows | Horizontal: more queue workers |
| Job ledger | Partitioning by workflow_id or date |
| Step outputs | Archival of completed workflows |
| Advancer execution | Stateless: runs on any worker |
| Database locks | Short duration; minimal contention |

---

## 18. Glossary

| Term | Definition |
|------|------------|
| **Advancer** | Stateless function that evaluates workflow state and takes appropriate action |
| **Fan-in** | Pattern where parallel jobs complete and results are aggregated |
| **Fan-out** | Pattern where a single step dispatches multiple parallel jobs |
| **Idempotency** | Property where an operation produces the same result if executed multiple times |
| **Job ledger** | Database table tracking every job's lifecycle |
| **Orchestrated job** | A queue job that participates in workflow orchestration |
| **Step** | Named unit of work within a workflow definition |
| **Step output** | Typed data produced by a step, consumed by subsequent steps |
| **Step run** | A single attempt to execute a step |
| **Workflow context** | Domain data available to all jobs in a workflow |
| **Workflow definition** | Static specification of steps and their relationships |
| **Workflow instance** | A single execution of a workflow definition |
| **Zombie job** | A job marked RUNNING whose worker has died |

---

## Appendix A: Comparison with Alternatives

| Feature | This Package | Laravel Workflow Package | Event-Based (DIY) |
|---------|--------------|--------------------------|-------------------|
| Running state tracking | ✓ First-class | ✗ Not available | ✗ Manual |
| Typed data passing | ✓ Enforced | ✗ JSON blobs | ✗ Ad-hoc |
| Fan-out/fan-in | ✓ Built-in | Partial | ✗ Manual |
| Retry at step level | ✓ Built-in | Limited | ✗ Manual |
| Operational dashboard support | ✓ Queryable | Limited | ✗ Custom |
| Zombie detection | ✓ Built-in | ✗ Not available | ✗ Manual |
| External triggers | ✓ Native | Limited | ✓ Possible |
| Long pause (days) | ✓ Zero compute | Varies | Varies |

---

## Appendix B: Decision Log

| Decision | Options Considered | Choice | Rationale |
|----------|-------------------|--------|-----------|
| Orchestration model | Polling daemon vs event-driven | Event-driven | Zero idle compute; immediate response |
| Data passing | JSON blobs vs typed contracts | Typed contracts | IDE support; refactoring; validation |
| Fan-in race handling | Locking vs atomic update | Atomic conditional update | Simpler; no lock management |
| Zombie detection | Heartbeat vs timeout | Timeout | Simpler; works with any queue driver |
| Step retry tracking | Update existing vs new record | New record | Audit trail; immutability |
| Pause semantics | Cancel in-flight vs wait | Wait for completion | Simpler state management |

---

*End of Architecture Specification*
