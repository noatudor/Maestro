<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('definition_key', 255);
            $table->string('definition_version', 20);
            $table->string('state', 50);
            $table->string('current_step_key', 255)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_reason', 500)->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('locked_by', 255)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index('state', 'idx_workflows_state');
            $table->index('definition_key', 'idx_workflows_definition_key');
            $table->index('created_at', 'idx_workflows_created_at');
            $table->index('updated_at', 'idx_workflows_updated_at');
            $table->index(['definition_key', 'state'], 'idx_workflows_definition_state');
            $table->index(['state', 'updated_at'], 'idx_workflows_state_updated');
            $table->index(['locked_by', 'locked_at'], 'idx_workflows_lock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_workflows');
    }
};
