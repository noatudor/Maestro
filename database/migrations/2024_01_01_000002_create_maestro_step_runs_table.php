<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_step_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('step_key', 255);
            $table->unsignedInteger('attempt')->default(1);
            $table->string('status', 50);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('completed_job_count')->default(0);
            $table->unsignedInteger('failed_job_count')->default(0);
            $table->unsignedInteger('total_job_count')->default(0);
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_step_runs_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->onDelete('cascade');

            $table->index('workflow_id', 'idx_step_runs_workflow');
            $table->index('status', 'idx_step_runs_status');
            $table->index('step_key', 'idx_step_runs_step_key');
            $table->index(['workflow_id', 'step_key'], 'idx_step_runs_workflow_step');
            $table->index(['workflow_id', 'status'], 'idx_step_runs_workflow_status');
            $table->index(['workflow_id', 'step_key', 'attempt'], 'idx_step_runs_workflow_step_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_step_runs');
    }
};
