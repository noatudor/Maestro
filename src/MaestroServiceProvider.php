<?php

declare(strict_types=1);

namespace Maestro\Workflow;

use Maestro\Workflow\Application\Job\DefaultIdempotencyKeyGenerator;
use Maestro\Workflow\Application\Orchestration\WorkflowManagementService;
use Maestro\Workflow\Commands\MaestroInstallCommand;
use Maestro\Workflow\Commands\WorkflowStatusCommand;
use Maestro\Workflow\Console\Commands\CancelWorkflowCommand;
use Maestro\Workflow\Console\Commands\CheckWorkflowTimeoutsCommand;
use Maestro\Workflow\Console\Commands\CleanupWorkflowsCommand;
use Maestro\Workflow\Console\Commands\DetectZombieJobsCommand;
use Maestro\Workflow\Console\Commands\PauseWorkflowCommand;
use Maestro\Workflow\Console\Commands\ResumeWorkflowsCommand;
use Maestro\Workflow\Console\Commands\RetryWorkflowCommand;
use Maestro\Workflow\Contracts\IdempotencyKeyGenerator;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\OutputSerializer;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentJobLedgerRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepOutputRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepRunRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentWorkflowRepository;
use Maestro\Workflow\Infrastructure\Serialization\PhpOutputSerializer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class MaestroServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('maestro')
            ->hasConfigFile()
            ->hasMigrations([
                'create_maestro_workflows_table',
                'create_maestro_step_runs_table',
                'create_maestro_job_ledger_table',
                'create_maestro_step_outputs_table',
            ])
            ->hasCommands([
                MaestroInstallCommand::class,
                WorkflowStatusCommand::class,
                RetryWorkflowCommand::class,
                ResumeWorkflowsCommand::class,
                PauseWorkflowCommand::class,
                CancelWorkflowCommand::class,
                CleanupWorkflowsCommand::class,
                DetectZombieJobsCommand::class,
                CheckWorkflowTimeoutsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerBindings();
    }

    public function packageBooted(): void
    {
        $this->registerEventListeners();
    }

    private function registerBindings(): void
    {
        $this->app->bind(WorkflowManager::class, WorkflowManagementService::class);
        $this->app->bind(WorkflowRepository::class, EloquentWorkflowRepository::class);
        $this->app->bind(StepRunRepository::class, EloquentStepRunRepository::class);
        $this->app->bind(StepOutputRepository::class, EloquentStepOutputRepository::class);
        $this->app->bind(JobLedgerRepository::class, EloquentJobLedgerRepository::class);
        $this->app->bind(OutputSerializer::class, PhpOutputSerializer::class);
        $this->app->bind(IdempotencyKeyGenerator::class, DefaultIdempotencyKeyGenerator::class);
    }

    private function registerEventListeners(): void
    {
        // Event listeners will be registered here
    }
}
