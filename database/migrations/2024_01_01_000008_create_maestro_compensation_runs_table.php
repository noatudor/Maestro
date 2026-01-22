<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_compensation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('step_key', 255);
            $table->string('compensation_job_class', 512);
            $table->unsignedSmallInteger('execution_order');
            $table->string('status', 50)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->uuid('current_job_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->longText('failure_trace')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_compensation_runs_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->cascadeOnDelete();

            $table->index('workflow_id', 'idx_compensation_runs_workflow');
            $table->index(['workflow_id', 'status'], 'idx_compensation_runs_workflow_status');
            $table->index(['workflow_id', 'execution_order'], 'idx_compensation_runs_workflow_order');
            $table->index(['status', 'created_at'], 'idx_compensation_runs_status_created');
            $table->unique(['workflow_id', 'step_key'], 'uniq_compensation_runs_workflow_step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_compensation_runs');
    }
};
