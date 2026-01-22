<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_resolution_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('decision_type', 50);
            $table->string('decided_by', 255)->nullable();
            $table->text('reason')->nullable();
            $table->string('retry_from_step_key', 255)->nullable();
            $table->json('compensate_step_keys')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_resolution_decisions_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->cascadeOnDelete();

            $table->index('workflow_id', 'idx_resolution_decisions_workflow');
            $table->index(['workflow_id', 'created_at'], 'idx_resolution_decisions_workflow_created');
            $table->index('decision_type', 'idx_resolution_decisions_type');
            $table->index('created_at', 'idx_resolution_decisions_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_resolution_decisions');
    }
};
