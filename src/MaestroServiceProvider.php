<?php

declare(strict_types=1);

namespace Maestro\Workflow;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Route;
use Maestro\Workflow\Application\Job\DefaultIdempotencyKeyGenerator;
use Maestro\Workflow\Application\Orchestration\Listeners\JobCompletedListener;
use Maestro\Workflow\Application\Orchestration\Listeners\JobFailedListener;
use Maestro\Workflow\Application\Orchestration\WorkflowManagementService;
use Maestro\Workflow\Application\Query\WorkflowQueryService;
use Maestro\Workflow\Commands\MaestroInstallCommand;
use Maestro\Workflow\Commands\WorkflowStatusCommand;
use Maestro\Workflow\Console\Commands\CancelWorkflowCommand;
use Maestro\Workflow\Console\Commands\CheckTriggerTimeoutsCommand;
use Maestro\Workflow\Console\Commands\CheckWorkflowTimeoutsCommand;
use Maestro\Workflow\Console\Commands\CleanupWorkflowsCommand;
use Maestro\Workflow\Console\Commands\CompensateWorkflowCommand;
use Maestro\Workflow\Console\Commands\DetectZombieJobsCommand;
use Maestro\Workflow\Console\Commands\DispatchPollsCommand;
use Maestro\Workflow\Console\Commands\GraphWorkflowCommand;
use Maestro\Workflow\Console\Commands\ListWorkflowsCommand;
use Maestro\Workflow\Console\Commands\PauseWorkflowCommand;
use Maestro\Workflow\Console\Commands\ProcessAutoRetriesCommand;
use Maestro\Workflow\Console\Commands\ProcessScheduledResumesCommand;
use Maestro\Workflow\Console\Commands\RecoverPollsCommand;
use Maestro\Workflow\Console\Commands\ResolveWorkflowCommand;
use Maestro\Workflow\Console\Commands\ResumeWorkflowsCommand;
use Maestro\Workflow\Console\Commands\RetryCompensationCommand;
use Maestro\Workflow\Console\Commands\RetryFromStepCommand;
use Maestro\Workflow\Console\Commands\RetryWorkflowCommand;
use Maestro\Workflow\Console\Commands\SkipCompensationCommand;
use Maestro\Workflow\Console\Commands\StartWorkflowCommand;
use Maestro\Workflow\Console\Commands\ValidateDefinitionsCommand;
use Maestro\Workflow\Contracts\BranchDecisionRepository;
use Maestro\Workflow\Contracts\CompensationRunRepository;
use Maestro\Workflow\Contracts\IdempotencyKeyGenerator;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Contracts\OutputSerializer;
use Maestro\Workflow\Contracts\PollAttemptRepository;
use Maestro\Workflow\Contracts\ResolutionDecisionRepository;
use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Maestro\Workflow\Contracts\TriggerPayloadRepository;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Http\Authentication\HmacTriggerAuthenticator;
use Maestro\Workflow\Http\Authentication\NullTriggerAuthenticator;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentBranchDecisionRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentCompensationRunRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentJobLedgerRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentPollAttemptRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentResolutionDecisionRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepOutputRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentStepRunRepository;
use Maestro\Workflow\Infrastructure\Persistence\Repositories\EloquentTriggerPayloadRepository;
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
                'create_maestro_resolution_decisions_table',
                'add_auto_retry_columns_to_maestro_workflows_table',
                'add_superseding_columns_to_maestro_step_runs_table',
                'create_maestro_compensation_runs_table',
                'add_compensation_columns_to_maestro_workflows_table',
                'add_branching_columns_to_maestro_step_runs_table',
                'create_maestro_branch_decisions_table',
                'add_polling_columns_to_maestro_step_runs_table',
                'create_maestro_poll_attempts_table',
                'add_trigger_columns_to_maestro_workflows_table',
                'create_maestro_trigger_payloads_table',
            ])
            ->hasCommands([
                MaestroInstallCommand::class,
                WorkflowStatusCommand::class,
                StartWorkflowCommand::class,
                ListWorkflowsCommand::class,
                RetryWorkflowCommand::class,
                RetryFromStepCommand::class,
                ResumeWorkflowsCommand::class,
                PauseWorkflowCommand::class,
                CancelWorkflowCommand::class,
                CleanupWorkflowsCommand::class,
                DetectZombieJobsCommand::class,
                CheckWorkflowTimeoutsCommand::class,
                ValidateDefinitionsCommand::class,
                GraphWorkflowCommand::class,
                ResolveWorkflowCommand::class,
                ProcessAutoRetriesCommand::class,
                CompensateWorkflowCommand::class,
                SkipCompensationCommand::class,
                RetryCompensationCommand::class,
                DispatchPollsCommand::class,
                RecoverPollsCommand::class,
                CheckTriggerTimeoutsCommand::class,
                ProcessScheduledResumesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerBindings();
    }

    public function packageBooted(): void
    {
        $this->registerEventListeners();
        $this->registerApiRoutes();
    }

    private function registerBindings(): void
    {
        $this->app->bind(WorkflowManager::class, WorkflowManagementService::class);
        $this->app->bind(WorkflowRepository::class, EloquentWorkflowRepository::class);
        $this->app->bind(StepRunRepository::class, EloquentStepRunRepository::class);
        $this->app->bind(StepOutputRepository::class, EloquentStepOutputRepository::class);
        $this->app->bind(JobLedgerRepository::class, EloquentJobLedgerRepository::class);
        $this->app->bind(ResolutionDecisionRepository::class, EloquentResolutionDecisionRepository::class);
        $this->app->bind(CompensationRunRepository::class, EloquentCompensationRunRepository::class);
        $this->app->bind(BranchDecisionRepository::class, EloquentBranchDecisionRepository::class);
        $this->app->bind(PollAttemptRepository::class, EloquentPollAttemptRepository::class);
        $this->app->bind(TriggerPayloadRepository::class, EloquentTriggerPayloadRepository::class);
        $this->app->bind(OutputSerializer::class, PhpOutputSerializer::class);
        $this->app->bind(IdempotencyKeyGenerator::class, DefaultIdempotencyKeyGenerator::class);

        $this->app->singleton(WorkflowQueryService::class);
        $this->app->singleton(WorkflowDefinitionRegistry::class);
        $this->app->singleton(Maestro::class);

        $this->registerTriggerAuthenticator();
    }

    private function registerTriggerAuthenticator(): void
    {
        $this->app->bind(TriggerAuthenticator::class, static function (): TriggerAuthenticator {
            $driver = config('maestro.trigger_auth.driver', 'null');

            if ($driver === 'hmac') {
                /** @var string $secret */
                $secret = config('maestro.trigger_auth.hmac.secret', '');
                /** @var int $maxDrift */
                $maxDrift = config('maestro.trigger_auth.hmac.max_timestamp_drift_seconds', 300);

                return new HmacTriggerAuthenticator(
                    secret: $secret,
                    maxTimestampDrift: $maxDrift,
                );
            }

            return new NullTriggerAuthenticator();
        });
    }

    private function registerEventListeners(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $dispatcher->listen(JobProcessed::class, JobCompletedListener::class);
        $dispatcher->listen(JobFailed::class, JobFailedListener::class);
    }

    private function registerApiRoutes(): void
    {
        /** @var bool $enabled */
        $enabled = config('maestro.api.enabled', true);

        if (! $enabled) {
            return;
        }

        Route::group([
            'prefix' => config('maestro.api.prefix', 'api/maestro'),
            'middleware' => config('maestro.api.middleware', ['api']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }
}
