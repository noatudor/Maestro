<?php

declare(strict_types=1);

namespace Maestro\Workflow;

use Maestro\Workflow\Commands\MaestroInstallCommand;
use Maestro\Workflow\Commands\WorkflowStatusCommand;
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
        // Repository bindings will be added here
        // $this->app->bind(WorkflowRepository::class, EloquentWorkflowRepository::class);
    }

    private function registerEventListeners(): void
    {
        // Event listeners will be registered here
    }
}
