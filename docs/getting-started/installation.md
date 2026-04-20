# Installation

## Requirements

Before installing Maestro, ensure your system meets the following requirements:

- **PHP 8.4** or higher
- **Laravel 11.x** or **12.x**
- **Database**: MySQL 8.0+, PostgreSQL 14+, or SQLite (for development)

## Installation Steps

### 1. Install via Composer

```bash
composer require maestro/workflow
```

### 2. Publish Configuration

Publish the Maestro configuration file:

```bash
php artisan vendor:publish --tag=maestro-config
```

This creates a `config/maestro.php` file where you can customize all settings.

### 3. Run Migrations

Run the database migrations to create the required tables:

```bash
php artisan migrate
```

Maestro creates the following tables:
- `maestro_workflows` - Workflow instances
- `maestro_step_runs` - Step execution records
- `maestro_jobs` - Job ledger for tracking individual jobs
- `maestro_outputs` - Typed step outputs

### 4. Configure Queue Worker

Maestro jobs run through Laravel's queue system. Ensure you have a queue worker running:

```bash
php artisan queue:work
```

For production, configure a process manager like Supervisor to keep the queue worker running.

## Verification

Verify the installation by checking the Maestro service is available:

```php
use Maestro\Workflow\Maestro;

// Should not throw an exception
$maestro = app(Maestro::class);
```

## Environment Configuration

Add these environment variables to your `.env` file:

```env
# Queue connection for workflow jobs
MAESTRO_QUEUE_CONNECTION=redis
MAESTRO_QUEUE_NAME=workflows

# Lock timeout for concurrent access
MAESTRO_LOCK_TIMEOUT=5

# Zombie job detection interval (seconds)
MAESTRO_ZOMBIE_THRESHOLD=3600
```

## Next Steps

- [Quick Start Guide](quick-start.md) - Create your first workflow
- [Configuration Reference](../configuration/reference.md) - Explore all options
- [Core Concepts](../concepts/workflows.md) - Understand the architecture
