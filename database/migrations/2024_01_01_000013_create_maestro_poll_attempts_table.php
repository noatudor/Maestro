<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_poll_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('step_run_id');
            $table->unsignedInteger('attempt_number');
            $table->uuid('job_id')->nullable();
            $table->boolean('result_complete')->default(false);
            $table->boolean('result_continue')->default(true);
            $table->unsignedInteger('next_interval_seconds')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->foreign('step_run_id', 'fk_poll_attempts_step_run')
                ->references('id')
                ->on('maestro_step_runs')
                ->onDelete('cascade');

            $table->index('step_run_id', 'idx_poll_attempts_step_run');
            $table->index(['step_run_id', 'attempt_number'], 'idx_poll_attempts_step_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_poll_attempts');
    }
};
