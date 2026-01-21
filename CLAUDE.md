# Maestro - Development Guidelines

This document defines the coding standards, conventions, and architectural decisions for the Maestro workflow orchestration package. All contributors must adhere to these guidelines.

## Project Overview

Maestro is a high-performance Laravel workflow orchestration package designed to handle millions of workflows. It provides typed data passing, explicit state tracking, and full operational transparency.

- **Namespace**: `Maestro\Workflow`
- **PHP Version**: 8.4+ (latest stable only)
- **Laravel Version**: 11.x and 12.x
- **License**: MIT

## Development Environment

> **CRITICAL: All commands MUST run inside Laravel Sail.**
>
> The Sail container includes PHP 8.4 with all required extensions:
> - **pcov** for code coverage
> - **redis**, **mysql**, **pgsql** for database drivers
> - All Laravel-required extensions
>
> Running commands locally (without Sail) will fail.

### Starting the Environment

```bash
# Start Sail containers (REQUIRED before running ANY commands)
./vendor/bin/sail up -d

# Stop containers when done
./vendor/bin/sail down
```

### Verifying Sail is Running

Before running any development commands, ensure Sail is running:

```bash
# Check if containers are running
docker ps | grep sail
```

## Quick Reference

> **ALWAYS prefix commands with `./vendor/bin/sail`**

All composer scripts are configured to run inside the Sail container:

```bash
# Run tests (ALWAYS use Sail)
./vendor/bin/sail composer test

# Run tests with coverage (must be ≥95%)
./vendor/bin/sail composer test-coverage

# Static analysis (PHPStan level 9)
./vendor/bin/sail composer analyse

# Format code
./vendor/bin/sail composer format

# Run all checks (before committing)
./vendor/bin/sail composer check

# Run Rector refactoring
./vendor/bin/sail composer refactor
```

### Direct Sail Commands

You can run tools directly via Sail (note the `bin` shortcut):

```bash
# Run specific test file
./vendor/bin/sail bin pest tests/Unit/ValueObjects/WorkflowIdTest.php

# Run PHPStan with verbose output
./vendor/bin/sail bin phpstan analyse --verbose

# Run Pint check (dry-run)
./vendor/bin/sail bin pint --test
```

### Common Mistakes to Avoid

```bash
# WRONG - Running locally will fail (no pcov, wrong PHP version, etc.)
./vendor/bin/pest
./vendor/bin/phpstan analyse
composer test

# CORRECT - Always use Sail
./vendor/bin/sail bin pest
./vendor/bin/sail bin phpstan analyse
./vendor/bin/sail composer test
```

---

## Code Standards

### Strictness Requirements

Every PHP file MUST begin with:

```php
<?php

declare(strict_types=1);
```

### Class Design Principles

1. **Final by default**: All classes are `final` unless explicitly designed as extension points
2. **Readonly by default**: All properties are `readonly` unless mutation is required
3. **Private by default**: Start with `private` visibility, promote only when needed
4. **No mixed types**: Never use `mixed` type hints - be explicit

```php
// CORRECT
final readonly class WorkflowInstance
{
    private function __construct(
        public WorkflowId $id,
        public WorkflowState $state,
        public CarbonImmutable $createdAt,
    ) {}

    public static function create(WorkflowDefinition $definition): self
    {
        return new self(
            id: WorkflowId::generate(),
            state: WorkflowState::Pending,
            createdAt: CarbonImmutable::now(),
        );
    }
}

// INCORRECT - violates multiple rules
class WorkflowInstance
{
    public mixed $data;
    protected $state; // No type hint

    public function __construct() {} // Public constructor
}
```

### Constructor Pattern

Use constructor property promotion with private constructors and static factory methods:

```php
final readonly class StepOutput
{
    private function __construct(
        public StepKey $stepKey,
        public mixed $payload, // Exception: payload can be mixed when validated elsewhere
    ) {}

    public static function fromStep(Step $step, mixed $payload): self
    {
        return new self(
            stepKey: $step->key(),
            payload: $payload,
        );
    }
}
```

### Interface Naming

Use descriptive names without prefixes or suffixes:

```php
// CORRECT
interface WorkflowRepository {}
interface StepExecutor {}
interface OutputSerializer {}

// INCORRECT
interface IWorkflowRepository {}      // No "I" prefix
interface WorkflowRepositoryInterface {} // No "Interface" suffix
interface WorkflowRepositoryContract {}  // No "Contract" suffix
```

---

## Comments Policy

### No Unnecessary Comments

Code should be self-documenting. Comments explaining WHAT the code does are prohibited.

```php
// INCORRECT - obvious comment
// Get the workflow by ID
$workflow = $this->repository->find($id);

// INCORRECT - restating the code
// Loop through all steps
foreach ($steps as $step) {

// INCORRECT - TODO in main branch
// TODO: implement this later
```

### Acceptable Comments

Only these comment types are permitted:

1. **WHY comments** - Explain non-obvious business decisions:
```php
// Workflows cannot transition directly from PAUSED to SUCCEEDED
// because all in-flight jobs must complete first (see spec §6.1)
if ($workflow->isPaused()) {
    throw new InvalidStateTransition($workflow->state, WorkflowState::Succeeded);
}
```

2. **Warning comments** - Alert to non-obvious consequences:
```php
// WARNING: This query can be slow on tables with >1M rows
// Consider using chunked processing for bulk operations
```

3. **Reference comments** - Link to external resources:
```php
// See: https://example.com/docs/workflow-states
```

### PHPDoc Policy

- **No PHPDoc for typed code**: If PHP types fully describe the signature, omit PHPDoc
- **Required for @throws**: Document all thrown exceptions
- **Required for @param descriptions**: Only when parameter name isn't self-explanatory
- **No @return duplication**: Don't repeat the return type in PHPDoc

```php
// CORRECT - types are sufficient
public function find(WorkflowId $id): ?WorkflowInstance

// CORRECT - @throws is documented
/**
 * @throws WorkflowNotFoundException When workflow doesn't exist
 * @throws WorkflowLockedException When workflow is locked by another process
 */
public function findOrFail(WorkflowId $id): WorkflowInstance

// INCORRECT - redundant PHPDoc
/**
 * Find a workflow by ID.
 *
 * @param WorkflowId $id The workflow ID
 * @return WorkflowInstance|null The workflow instance or null
 */
public function find(WorkflowId $id): ?WorkflowInstance
```

---

## Architecture Rules

### Layer Boundaries

```
┌─────────────────────────────────────────────────┐
│  Contracts (interfaces, enums, value objects)   │ ← No dependencies
├─────────────────────────────────────────────────┤
│  Domain (core business logic)                   │ ← Depends only on Contracts
├─────────────────────────────────────────────────┤
│  Application (use cases, orchestration)         │ ← Depends on Domain, Contracts
├─────────────────────────────────────────────────┤
│  Infrastructure (persistence, queues, events)   │ ← Can depend on all layers
└─────────────────────────────────────────────────┘
```

**Rules enforced by architecture tests:**
- Domain MUST NOT depend on Infrastructure
- Domain MUST NOT depend on Application
- Contracts MUST NOT depend on any other layer
- No circular dependencies between namespaces

### Dependency Injection

Constructor injection only. No service location, no method injection, no facades.

```php
// CORRECT
final readonly class WorkflowAdvancer
{
    public function __construct(
        private WorkflowRepository $workflows,
        private StepExecutor $executor,
        private EventDispatcher $events,
    ) {}
}

// INCORRECT - service location
public function advance(): void
{
    $executor = app(StepExecutor::class); // Never do this
}

// INCORRECT - facade usage
public function advance(): void
{
    Event::dispatch(new WorkflowAdvanced()); // Never use facades
}
```

### Trait Policy

Avoid traits. Use composition instead.

**Only permitted for:**
- Laravel framework integration (e.g., `InteractsWithQueue`)
- Test helpers in the test suite

```php
// INCORRECT - sharing code via traits
trait HasWorkflowState
{
    public function isPending(): bool { /* ... */ }
}

// CORRECT - composition
final readonly class WorkflowStateChecker
{
    public function isPending(WorkflowInstance $workflow): bool { /* ... */ }
}
```

### Magic Methods Policy

No magic methods except:
- `__construct` (private, with static factories)
- `__serialize` / `__unserialize` (for serialization control)

```php
// INCORRECT
public function __get(string $name): mixed { /* ... */ }
public function __call(string $method, array $args): mixed { /* ... */ }
```

---

## Data Patterns

### Value Objects

All identifiers and domain concepts use value objects:

```php
final readonly class WorkflowId
{
    private function __construct(
        public string $value,
    ) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

### UUIDs

Use UUIDv7 (time-ordered) for all identifiers:

```php
use Ramsey\Uuid\Uuid;

$id = Uuid::uuid7()->toString();
```

### DateTime Handling

Use `CarbonImmutable` exclusively:

```php
use Carbon\CarbonImmutable;

// CORRECT
public function __construct(
    public CarbonImmutable $createdAt,
    public ?CarbonImmutable $completedAt,
) {}

// INCORRECT - mutable Carbon
public function __construct(
    public Carbon $createdAt, // Mutable - don't use
) {}
```

### Typed Collections

Create type-specific collection classes:

```php
/**
 * @extends AbstractCollection<int, Step>
 */
final class StepCollection extends AbstractCollection
{
    public function pending(): self
    {
        return $this->filter(fn (Step $step) => $step->isPending());
    }

    public function findByKey(StepKey $key): ?Step
    {
        return $this->first(fn (Step $step) => $step->key()->equals($key));
    }
}
```

### Enums for States

Use backed enums for all state values:

```php
enum WorkflowState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
```

---

## Error Handling

### Exception Hierarchy

```
MaestroException (base)
├── WorkflowException
│   ├── WorkflowNotFoundException
│   ├── WorkflowLockedException
│   ├── InvalidStateTransitionException
│   └── WorkflowAlreadyCompletedException
├── StepException
│   ├── StepNotFoundException
│   ├── StepDependencyException
│   └── StepTimeoutException
├── JobException
│   ├── JobNotFoundException
│   └── JobExecutionException
└── ConfigurationException
    ├── InvalidWorkflowDefinitionException
    └── MissingRequiredOutputException
```

### Exception Design

```php
final class WorkflowNotFoundException extends WorkflowException
{
    public static function withId(WorkflowId $id): self
    {
        return new self(
            message: "Workflow not found: {$id->value}",
            code: self::CODE_NOT_FOUND,
        );
    }
}

// Usage
throw WorkflowNotFoundException::withId($workflowId);
```

---

## Testing Standards

### Test Organization

```
tests/
├── Unit/           # Isolated tests, no Laravel, no database
├── Feature/        # Laravel application tests with database
├── Integration/    # External service integration tests
└── Architecture/   # Pest architecture tests for dependency rules
```

### Test Naming

Use Pest with `it()` and snake_case descriptions:

```php
// CORRECT
it('throws when workflow is already completed', function () {
    // ...
});

it('dispatches jobs for all pending steps', function () {
    // ...
});

// INCORRECT
test('testWorkflowCompletion', function () { // Wrong format
    // ...
});
```

### Assertion Style

Use Pest expectations exclusively:

```php
// CORRECT
expect($workflow->state)->toBe(WorkflowState::Running);
expect($steps)->toHaveCount(3);
expect($result)->toBeInstanceOf(WorkflowInstance::class);
expect(fn () => $service->process($invalid))->toThrow(ValidationException::class);

// INCORRECT - PHPUnit style
$this->assertEquals(WorkflowState::Running, $workflow->state);
$this->assertCount(3, $steps);
```

### Mocking Strategy

Prefer fake implementations over mocks:

```php
// CORRECT - In-memory fake
final class InMemoryWorkflowRepository implements WorkflowRepository
{
    /** @var array<string, WorkflowInstance> */
    private array $workflows = [];

    public function save(WorkflowInstance $workflow): void
    {
        $this->workflows[$workflow->id->value] = $workflow;
    }

    public function find(WorkflowId $id): ?WorkflowInstance
    {
        return $this->workflows[$id->value] ?? null;
    }
}

// Use in tests
beforeEach(function () {
    $this->repository = new InMemoryWorkflowRepository();
    $this->service = new WorkflowService($this->repository);
});

// INCORRECT - Mockery when fake would work
$mock = Mockery::mock(WorkflowRepository::class);
$mock->shouldReceive('find')->andReturn($workflow);
```

### Coverage Requirements

- **Minimum**: 95% line coverage
- **Critical paths**: 100% coverage required for state transitions, concurrency control
- **Exclude from coverage**: Pure data classes, framework boilerplate

---

## Database Standards

### Migration Conventions

1. **No anonymous classes**: Use named migration classes
2. **Explicit down() methods**: All migrations must be reversible
3. **Named foreign keys**: Explicit constraint names for all foreign keys
4. **Explicit index names**: Named indexes for composite keys

```php
// CORRECT
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('definition_key', 255);
            $table->string('state', 50)->index('idx_workflows_state');
            $table->timestamp('created_at')->index('idx_workflows_created');

            $table->index(['definition_key', 'state'], 'idx_workflows_definition_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_workflows');
    }
};
```

### Index Strategy for Scale

Design for millions of rows:
- Composite indexes on frequently queried combinations
- Partial indexes where supported (PostgreSQL)
- UUID v7 for naturally ordered primary keys
- Partition-ready schema design

### Model Approach

Thin models with custom query builders:

```php
// Model - minimal, no business logic
final class WorkflowModel extends Model
{
    protected $table = 'maestro_workflows';
    protected $keyType = 'string';
    public $incrementing = false;

    public function newEloquentBuilder($query): WorkflowQueryBuilder
    {
        return new WorkflowQueryBuilder($query);
    }
}

// Query builder - complex queries
final class WorkflowQueryBuilder extends Builder
{
    public function running(): self
    {
        return $this->where('state', WorkflowState::Running->value);
    }

    public function staleRunning(CarbonImmutable $threshold): self
    {
        return $this->running()->where('updated_at', '<', $threshold);
    }
}
```

---

## Event Naming

Use past tense domain events:

```php
// CORRECT - past tense, domain-centric
final readonly class WorkflowStarted {}
final readonly class StepCompleted {}
final readonly class JobFailed {}
final readonly class WorkflowPaused {}

// INCORRECT
final readonly class WorkflowWasStarted {} // Redundant "Was"
final readonly class StartWorkflow {}       // Command, not event
final readonly class OnWorkflowStart {}     // Handler naming
```
    
---

## Import Ordering

Alphabetical within groups, separated by blank lines:

```php
<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Contracts\StepExecutor;
use Maestro\Workflow\Domain\Events\WorkflowStarted;
use Maestro\Workflow\Domain\ValueObjects\WorkflowId;
```

Order:
1. PHP core classes
2. External package classes (Illuminate, Carbon, etc.)
3. Internal package classes

---

## Code Metrics

### Enforced Limits

| Metric | Limit |
|--------|-------|
| Lines per method | 20 max |
| Lines per class | 200 max |
| Cyclomatic complexity | 10 max |
| Parameters per method | 4 max (use value objects for more) |
| Dependencies per class | 5 max |

### PHPStan Configuration

Level 9 (maximum) with strict rules:

```neon
parameters:
    level: 9
    paths:
        - src
    treatPhpDocTypesAsCertain: false
    reportUnmatchedIgnoredErrors: true
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
```

---

## Git Workflow

### Commit Messages

Use Conventional Commits:

```
feat: add workflow pause functionality
fix: resolve race condition in fan-in completion
docs: update architecture documentation
refactor: extract step execution to dedicated class
test: add coverage for timeout scenarios
chore: update dependencies
perf: optimize workflow query for large datasets
```

Format:
```
<type>(<optional scope>): <description>

[optional body]

[optional footer]
```

### Branch Naming

```
feat/workflow-pause
fix/fanin-race-condition
refactor/step-executor
test/timeout-coverage
```

### Pull Request Process

1. All CI checks must pass
2. Approval preferred (not blocking for maintainers)
3. Squash merge to main
4. Delete branch after merge

---

## Tooling

### Required Tools

| Tool | Purpose | Config File |
|------|---------|-------------|
| Laravel Pint | Code formatting | `pint.json` |
| PHPStan | Static analysis | `phpstan.neon` |
| Rector | Automated refactoring | `rector.php` |
| Pest | Testing | `phpunit.xml` |
| PHPBench | Performance benchmarks | `phpbench.json` |
| CaptainHook | Git hooks | `captainhook.json` |

### Pre-commit Hooks

Automatically run on every commit:
1. Pint (formatting)
2. PHPStan (static analysis)
3. Pest (tests)

---

## Performance Requirements

### Benchmarks

Critical paths must be benchmarked:
- Workflow creation: < 5ms
- Step advancement: < 10ms
- Job dispatch: < 2ms
- State queries: < 1ms for indexed lookups

### Optimization Rules

1. No N+1 queries - use eager loading
2. Batch operations for bulk updates
3. Use database transactions appropriately
4. Cache immutable data (workflow definitions)
5. Use queue for all non-critical operations

---

## Security

### Vulnerability Reporting

Report security issues via public GitHub issues (per project policy).

### Security Rules

1. No user input in raw SQL
2. Validate all external input at boundaries
3. No secrets in code or config defaults
4. Use parameterized queries exclusively

---

## Versioning

### Semantic Versioning

- **MAJOR**: Breaking API changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

### Changelog

Maintain `CHANGELOG.md` following Keep a Changelog format:
- Group changes: Added, Changed, Deprecated, Removed, Fixed, Security
- Link to relevant PRs/issues
- Include migration guides for breaking changes

---

## File Templates

### New Class Template

```php
<?php

declare(strict_types=1);

namespace Maestro\Workflow\Domain;

final readonly class ClassName
{
    private function __construct(
        // properties
    ) {}

    public static function create(): self
    {
        return new self(
            // arguments
        );
    }
}
```

### New Test Template

```php
<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\ClassName;

describe('ClassName', function () {
    beforeEach(function () {
        // setup
    });

    it('does something specific', function () {
        // arrange
        // act
        // assert
        expect($result)->toBe($expected);
    });
});
```

---

## Checklist Before Committing

- [ ] `declare(strict_types=1)` in all PHP files
- [ ] All classes are `final` (unless extension point)
- [ ] All properties are `readonly` (unless mutation needed)
- [ ] No unnecessary comments
- [ ] No TODO/FIXME comments
- [ ] Tests written and passing
- [ ] Coverage ≥ 95%
- [ ] PHPStan passes at level 9
- [ ] Pint formatting applied
- [ ] Commit message follows Conventional Commits
