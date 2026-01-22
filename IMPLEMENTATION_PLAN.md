# Maestro Implementation Plan

This document outlines the phased implementation of the workflow orchestration package based on the architecture specification.

---

## Post-Implementation Steps (After Every Phase)

These steps must be completed after each implementation phase before moving to the next:

1. **Test Coverage**
   - Write unit tests for all new classes (target ≥95% coverage)
   - Write feature/integration tests for cross-component behavior
   - Ensure all state transitions have dedicated test cases
   - Test edge cases and failure scenarios

2. **Static Analysis**
   - Run PHPStan at level 9 with zero errors
   - Run Rector for automated refactoring compliance
   - Run Pint for code formatting

3. **Benchmarks** (for performance-critical components)
   - Benchmark critical path operations
   - Verify against spec requirements:
     - Workflow creation: < 5ms
     - Step advancement: < 10ms
     - Job dispatch: < 2ms
     - State queries: < 1ms for indexed lookups

4. **Architecture Tests**
   - Verify layer boundaries (Contracts → Domain → Application → Infrastructure)
   - Ensure no circular dependencies
   - Validate interface segregation

5. **Documentation**
   - Update inline PHPDoc for `@throws` declarations
   - Ensure public APIs are documented

---

## Phase 1: Foundation - Contracts & Value Objects

### Objective
Establish the type system foundation with enums, value objects, and core interfaces that the entire package depends on.

### Implementation Steps

1.1. **Create State Enums**
   - `WorkflowState` enum (Pending, Running, Paused, Succeeded, Failed, Cancelled)
   - `StepRunState` enum (Pending, Running, Succeeded, Failed)
   - `JobState` enum (Dispatched, Running, Succeeded, Failed)
   - Add helper methods (e.g., `isTerminal()`, valid transitions)

1.2. **Create Core Value Objects**
   - `WorkflowId` (UUIDv7 generation, string conversion, equality)
   - `StepRunId` (UUIDv7 generation, string conversion, equality)
   - `JobId` (UUIDv7 generation, string conversion, equality)
   - `StepKey` (validation, string representation)
   - `DefinitionKey` (validation, string representation)
   - `DefinitionVersion` (semantic versioning support)

1.3. **Define Core Interfaces**
   - `StepOutput` interface (stepKey method)
   - `MergeableOutput` interface (extends StepOutput, mergeWith method)
   - `WorkflowContext` interface (marker interface for context objects)
   - `ContextLoader` interface (load method returning WorkflowContext)

1.4. **Define Repository Interfaces**
   - `WorkflowRepository` interface
   - `StepRunRepository` interface
   - `JobLedgerRepository` interface
   - `StepOutputRepository` interface

1.5. **Create Base Exception Hierarchy**
   - `MaestroException` (base)
   - `WorkflowException` (WorkflowNotFound, WorkflowLocked, InvalidStateTransition, WorkflowAlreadyCancelled)
   - `StepException` (StepNotFound, StepDependencyNotMet, StepTimeout)
   - `JobException` (JobNotFound, JobExecutionFailed)
   - `ConfigurationException` (InvalidWorkflowDefinition, MissingRequiredOutput)

---

## Phase 2: Persistence Layer

### Objective
Create the database schema and Eloquent models for persisting workflow state.

### Implementation Steps

2.1. **Create Migrations**
   - `maestro_workflows` table (workflow instance storage)
   - `maestro_step_runs` table (step execution tracking)
   - `maestro_job_ledger` table (job lifecycle tracking)
   - `maestro_step_outputs` table (typed output storage)
   - Add all indexes as specified (composite indexes for scale)
   - Add foreign key constraints with explicit names

2.2. **Create Eloquent Models**
   - `WorkflowModel` (thin model, no business logic)
   - `StepRunModel` (thin model)
   - `JobLedgerModel` (thin model)
   - `StepOutputModel` (thin model)
   - Define relationships between models

2.3. **Create Custom Query Builders**
   - `WorkflowQueryBuilder` (running, paused, failed, stale scopes)
   - `StepRunQueryBuilder` (pending, running, by attempt scopes)
   - `JobLedgerQueryBuilder` (by status, by step run scopes)

2.4. **Implement Repository Classes**
   - `EloquentWorkflowRepository` implementing `WorkflowRepository`
   - `EloquentStepRunRepository` implementing `StepRunRepository`
   - `EloquentJobLedgerRepository` implementing `JobLedgerRepository`
   - `EloquentStepOutputRepository` implementing `StepOutputRepository`

2.5. **Create Domain Entity Hydrators**
   - Mappers to convert between Eloquent models and domain entities
   - Handle serialization/deserialization of step outputs

---

## Phase 3: Domain Entities

### Objective
Create the core domain entities that represent workflow runtime state.

### Implementation Steps

3.1. **Create WorkflowInstance Entity**
   - Immutable properties (id, definitionKey, version, createdAt)
   - Mutable state properties (state, currentStepKey, timestamps)
   - State transition methods with validation
   - Pause/resume/cancel/fail methods
   - Lock management properties

3.2. **Create StepRun Entity**
   - Immutable properties (id, workflowId, stepKey, attempt)
   - Status tracking with timestamps
   - Job count tracking (total, failed)
   - Failure information storage

3.3. **Create JobRecord Entity**
   - All job lifecycle properties
   - Status transitions with timestamp recording
   - Runtime duration calculation
   - Failure detail storage

3.4. **Create Domain Collections**
   - `StepRunCollection` (filtering by status, finding by key)
   - `JobRecordCollection` (filtering by status, aggregations)

---

## Phase 4: Workflow Definition System

### Objective
Create the declarative workflow and step definition system.

### Implementation Steps

4.1. **Create Step Definition Contracts**
   - `StepDefinition` interface (key, displayName, requires, produces)
   - `SingleJobStep` interface (job class, queue config)
   - `FanOutStep` interface (iterator, job factory, parallelism limit)
   - `ExternalWaitStep` interface (trigger identifier)
   - `ConditionalStep` interface (condition evaluator)

4.2. **Create Step Definition Builders**
   - Fluent builder for single job steps
   - Fluent builder for fan-out steps
   - Fluent builder for external wait steps
   - Support for failure policies, timeouts, retry config

4.3. **Create WorkflowDefinition Class**
   - Definition key and version
   - Ordered step collection
   - Context loader class reference
   - Validation of step dependency graph

4.4. **Create WorkflowDefinitionRegistry**
   - Registration of workflow definitions
   - Retrieval by definition key and version
   - Validation at registration time

4.5. **Implement Definition Validator**
   - Validate step output dependencies (requires vs produces)
   - Detect circular dependencies
   - Validate step keys are unique
   - Validate job classes exist

---

## Phase 5: Typed Data Passing System

### Objective
Implement the two-tier data passing system (workflow context and step outputs).

### Implementation Steps

5.1. **Create StepOutputStore**
   - `read(class-string<T>): T` method
   - `has(class-string): bool` method
   - `write(StepOutput): void` method
   - Handle MergeableOutput merging logic

5.2. **Create Output Serialization System**
   - `OutputSerializer` interface
   - `PhpOutputSerializer` implementation (native serialize)
   - Support for custom serializers per output type

5.3. **Create WorkflowContextProvider**
   - Load context via application-defined ContextLoader
   - Cache context for duration of job execution
   - Provide typed access to context object

5.4. **Implement Output Merge Strategy**
   - Detect MergeableOutput instances
   - Sequential merge for fan-out job outputs
   - Atomic write of merged result

5.5. **Create Dependency Checker**
   - Check if all required outputs exist for a step
   - Used by advancer to determine step eligibility

---

## Phase 6: Job Execution Framework

### Objective
Create the orchestrated job base and lifecycle tracking system.

### Implementation Steps

6.1. **Create OrchestratedJob Base Class**
   - Carry workflow/step/job correlation metadata
   - Provide access to WorkflowContext
   - Provide access to StepOutputStore (read and write)
   - Define abstract handle method for subclasses

6.2. **Create Job Lifecycle Tracking**
   - Middleware for recording job start (DISPATCHED → RUNNING)
   - Middleware for recording job completion (RUNNING → SUCCEEDED)
   - Exception handler for recording failures (RUNNING → FAILED)
   - Worker ID and runtime tracking

6.3. **Create Job Dispatch Service**
   - Generate job UUID before dispatch
   - Create job ledger entry on dispatch
   - Support queue/connection configuration
   - Support delayed dispatch

6.4. **Implement Idempotency Support**
   - Idempotency key generation interface
   - Duplicate execution detection at job start
   - Skip mechanism for already-completed jobs

6.5. **Create Zombie Job Detector**
   - Query for jobs in RUNNING state past timeout threshold
   - Mark stale jobs as FAILED
   - Trigger advancer for affected workflows
   - Schedulable command for periodic execution

---

## Phase 7: Orchestration Engine

### Objective
Implement the event-driven workflow advancer.

### Implementation Steps

7.1. **Create WorkflowAdvancer Service**
   - `evaluate(WorkflowId): void` main entry point
   - Lock acquisition before evaluation
   - Skip if workflow is terminal or paused
   - Determine next action based on current state

7.2. **Implement Step Finalization Logic**
   - Check if all jobs for current step are complete
   - Evaluate success criteria (all, partial, N-of-M)
   - Update step run status atomically
   - Apply failure policy if step failed

7.3. **Implement Step Dispatch Logic**
   - Determine next eligible step
   - Check dependency requirements
   - Create step run record
   - Dispatch jobs (single or fan-out)

7.4. **Implement Workflow Completion Logic**
   - Detect when all steps are complete
   - Transition workflow to SUCCEEDED
   - Handle pause-on-completion triggers

7.5. **Create Event Trigger Handlers**
   - Listener for job completion events
   - Handler for external trigger webhooks
   - Handler for manual resume/retry actions
   - Scheduled command for timeout checks

---

## Phase 8: Concurrency Control

### Objective
Implement race condition prevention and locking mechanisms.

### Implementation Steps

8.1. **Implement Workflow Locking**
   - Database row lock mechanism (SELECT FOR UPDATE)
   - Lock timeout handling
   - Automatic lock release on completion
   - `WorkflowLockedException` when lock unavailable

8.2. **Implement Atomic Step Finalization**
   - Conditional UPDATE for step status transitions
   - Check affected rows to determine winner
   - Prevent duplicate step finalization

8.3. **Implement Job Dispatch Idempotency**
   - Unique constraint on job UUID in ledger
   - Check-before-dispatch pattern
   - Handle duplicate dispatch attempts gracefully

8.4. **Implement Output Write Atomicity**
   - Lock output row during merge operations
   - Transaction wrapping for multi-write operations
   - Prevent lost updates during fan-in merge

---

## Phase 9: Parallelism - Fan-Out and Fan-In

### Objective
Implement parallel job execution patterns.

### Implementation Steps

9.1. **Create Fan-Out Dispatcher**
   - Accept iterator of items to process
   - Generate job instances via factory
   - Dispatch all jobs with same step run correlation
   - Record total job count on step run

9.2. **Implement Fan-In Detection**
   - Query job ledger for step run completion status
   - Count completed vs total jobs
   - Trigger step finalization when all complete

9.3. **Implement Partial Success Evaluation**
   - Success policies: All, Majority, N-of-M, BestEffort
   - Evaluate against actual job results
   - Determine step success/failure based on policy

9.4. **Implement Output Merging for Fan-Out**
   - Collect outputs from all completed jobs
   - Apply MergeableOutput.mergeWith sequentially
   - Store final merged output atomically

9.5. **Add Parallelism Limits (Optional)**
   - Configurable max concurrent jobs per step
   - Queue additional jobs as others complete
   - Track dispatched vs total for throttling

---

## Phase 10: Failure Handling & Recovery

### Objective
Implement comprehensive failure handling at all levels.

### Implementation Steps

10.1. **Implement Job Failure Recording**
   - Capture exception class, message, truncated trace
   - Record attempt count from Laravel queue
   - Distinguish retryable vs terminal failures

10.2. **Implement Step Failure Policies**
   - `FailWorkflow` policy implementation
   - `PauseWorkflow` policy implementation
   - `RetryStep` policy implementation
   - `SkipStep` policy implementation
   - `ContinueWithPartial` policy implementation

10.3. **Implement Step Retry Mechanism**
   - Create new step run with incremented attempt
   - Configure retry scope (all jobs vs failed only)
   - Support retry delay with backoff strategies
   - Respect max retry limits

10.4. **Implement Workflow Recovery Actions**
   - Manual retry from failed step
   - Resume paused workflow
   - Cancel workflow
   - Force-fail stuck workflow

10.5. **Create Recovery Commands**
   - Artisan command for manual workflow retry
   - Artisan command for bulk resume paused workflows
   - Artisan command for cleanup/cancel stuck workflows

---

## Phase 11: Event System

### Objective
Implement Laravel events for workflow lifecycle hooks.

### Implementation Steps

11.1. **Create Workflow Events**
   - `WorkflowCreated` event
   - `WorkflowStarted` event
   - `WorkflowPaused` event
   - `WorkflowResumed` event
   - `WorkflowSucceeded` event
   - `WorkflowFailed` event
   - `WorkflowCancelled` event

11.2. **Create Step Events**
   - `StepStarted` event
   - `StepSucceeded` event
   - `StepFailed` event
   - `StepRetried` event

11.3. **Create Job Events**
   - `JobDispatched` event
   - `JobStarted` event
   - `JobSucceeded` event
   - `JobFailed` event

11.4. **Wire Event Dispatching**
   - Dispatch events from appropriate locations in domain services
   - Ensure events fire after database commits (afterCommit)
   - Include relevant context in event payloads

---

## Phase 12: External Triggers & API

### Objective
Implement external trigger handling and public API.

### Implementation Steps

12.1. **Create External Trigger System**
   - Trigger identifier validation
   - Workflow lookup by trigger key
   - Resume workflow when trigger received
   - Support payload data from triggers

12.2. **Create Public Service API**
   - `WorkflowService` facade class
   - `startWorkflow(definitionKey, contextData): WorkflowId`
   - `pauseWorkflow(workflowId, reason): void`
   - `resumeWorkflow(workflowId): void`
   - `cancelWorkflow(workflowId): void`
   - `retryWorkflow(workflowId): void`
   - `getWorkflowStatus(workflowId): WorkflowStatus`

12.3. **Create Status Query API**
   - Query workflows by state
   - Query workflows by definition
   - Get workflow detail with steps and jobs
   - Get step output values

12.4. **Create Trigger Endpoint Controller**
   - Accept webhook POST requests
   - Validate trigger signature/authentication
   - Dispatch to appropriate workflow
   - Return acknowledgment response

---

## Phase 13: Artisan Commands

### Objective
Create CLI commands for workflow management and operations.

### Implementation Steps

13.1. **Create Workflow Management Commands**
   - `maestro:start` - Start a new workflow instance
   - `maestro:status` - Show workflow status and progress
   - `maestro:pause` - Pause a running workflow
   - `maestro:resume` - Resume a paused workflow
   - `maestro:cancel` - Cancel a workflow
   - `maestro:retry` - Retry a failed workflow

13.2. **Create Operational Commands**
   - `maestro:list` - List workflows with filtering
   - `maestro:cleanup` - Archive/delete old completed workflows
   - `maestro:detect-zombies` - Find and handle zombie jobs

13.3. **Create Development Commands**
   - `maestro:validate` - Validate all registered workflow definitions
   - `maestro:graph` - Output step dependency graph

---

## Phase 14: Service Provider & Configuration

### Objective
Create Laravel service provider and configuration system.

### Implementation Steps

14.1. **Create Configuration File**
   - Table name prefixes
   - Default retry settings
   - Zombie detection timeout
   - Lock timeout settings
   - Serializer configuration

14.2. **Create MaestroServiceProvider**
   - Register repository bindings
   - Register service bindings
   - Register event listeners
   - Register commands
   - Publish migrations and config

14.3. **Create Facade
   - `Maestro` facade for WorkflowService
   - Convenient static access to common operations

14.4. **Create Testing Utilities**
   - In-memory repository implementations (fakes)
   - Test helpers for workflow assertions
   - Factory classes for test data

---

## Phase 15: Integration & Polish

### Objective
Final integration, edge case handling, and polish.

### Implementation Steps

15.1. **End-to-End Integration Tests**
   - Complete workflow execution scenarios
   - Fan-out/fan-in scenarios
   - Failure and retry scenarios
   - External trigger scenarios
   - Concurrent execution scenarios

15.2. **Performance Optimization**
   - Query optimization review
   - Index effectiveness verification
   - Lock contention analysis
   - Memory usage profiling for large workflows

15.3. **Edge Case Handling**
   - Empty fan-out (zero items)
   - Workflow with single step
   - Immediate external trigger (no wait)
   - Rapid retry attempts
   - Very long-running jobs

15.4. **Documentation in Html format**
   - Usage guide with examples
   - Configuration reference
   - Troubleshooting guide

---

## Implementation Order Summary

| Phase | Focus Area | Dependencies |
|-------|------------|--------------|
| 1 | Foundation (Contracts & Value Objects) | None |
| 2 | Persistence Layer | Phase 1 |
| 3 | Domain Entities | Phases 1-2 |
| 4 | Workflow Definition System | Phases 1-3 |
| 5 | Typed Data Passing | Phases 1-4 |
| 6 | Job Execution Framework | Phases 1-5 |
| 7 | Orchestration Engine | Phases 1-6 |
| 8 | Concurrency Control | Phases 1-7 |
| 9 | Fan-Out/Fan-In | Phases 1-8 |
| 10 | Failure Handling | Phases 1-9 |
| 11 | Event System | Phases 1-7 |
| 12 | External Triggers & API | Phases 1-11 |
| 13 | Artisan Commands | Phases 1-12 |
| 14 | Service Provider | Phases 1-13 |
| 15 | Integration & Polish | All phases |

---

## Critical Path Components

These components are on the critical path and require 100% test coverage:

1. **State transition logic** (WorkflowState, StepRunState transitions)
2. **Workflow advancer** (evaluation and advancement logic)
3. **Atomic step finalization** (race condition prevention)
4. **Fan-in detection** (parallel job completion)
5. **Output merge logic** (MergeableOutput handling)
6. **Lock acquisition/release** (concurrency control)
7. **Failure policy execution** (retry, pause, fail decisions)

---

*End of Implementation Plan*
