<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_branch_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('branch_point_key', 255);
            $table->string('condition_class', 500);
            $table->json('selected_branches');
            $table->timestamp('evaluated_at');
            $table->json('input_summary')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_branch_decisions_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->cascadeOnDelete();

            $table->index('workflow_id', 'idx_branch_decisions_workflow');
            $table->index(['workflow_id', 'branch_point_key'], 'idx_branch_decisions_workflow_branch_point');
            $table->index(['workflow_id', 'evaluated_at'], 'idx_branch_decisions_workflow_evaluated');
            $table->index('evaluated_at', 'idx_branch_decisions_evaluated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_branch_decisions');
    }
};
