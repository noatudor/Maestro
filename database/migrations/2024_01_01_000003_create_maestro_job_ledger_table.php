<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_job_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->uuid('step_run_id');
            $table->uuid('job_uuid')->unique();
            $table->string('job_class', 500);
            $table->string('queue', 255);
            $table->string('status', 50);
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('dispatched_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->string('failure_class', 500)->nullable();
            $table->text('failure_message')->nullable();
            $table->text('failure_trace')->nullable();
            $table->string('worker_id', 255)->nullable();
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_job_ledger_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->onDelete('cascade');

            $table->foreign('step_run_id', 'fk_job_ledger_step_run')
                ->references('id')
                ->on('maestro_step_runs')
                ->onDelete('cascade');

            $table->index('workflow_id', 'idx_job_ledger_workflow');
            $table->index('step_run_id', 'idx_job_ledger_step_run');
            $table->index('status', 'idx_job_ledger_status');
            $table->index('dispatched_at', 'idx_job_ledger_dispatched');
            $table->index(['step_run_id', 'status'], 'idx_job_ledger_step_run_status');
            $table->index(['workflow_id', 'status'], 'idx_job_ledger_workflow_status');
            $table->index(['status', 'started_at'], 'idx_job_ledger_status_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_job_ledger');
    }
};
