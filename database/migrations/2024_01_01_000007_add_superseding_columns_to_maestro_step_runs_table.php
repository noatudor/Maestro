<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maestro_step_runs', function (Blueprint $table): void {
            $table->uuid('superseded_by_id')->nullable()->after('total_job_count');
            $table->timestamp('superseded_at')->nullable()->after('superseded_by_id');
            $table->string('retry_source', 50)->nullable()->after('superseded_at');

            $table->foreign('superseded_by_id', 'fk_step_runs_superseded_by')
                ->references('id')
                ->on('maestro_step_runs')
                ->onDelete('set null');

            $table->index('superseded_by_id', 'idx_step_runs_superseded_by');
            $table->index(['workflow_id', 'status', 'superseded_by_id'], 'idx_step_runs_workflow_status_superseded');
        });
    }

    public function down(): void
    {
        Schema::table('maestro_step_runs', function (Blueprint $table): void {
            $table->dropForeign(['superseded_by_id']);
            $table->dropIndex('idx_step_runs_superseded_by');
            $table->dropIndex('idx_step_runs_workflow_status_superseded');
            $table->dropColumn(['superseded_by_id', 'superseded_at', 'retry_source']);
        });
    }
};
