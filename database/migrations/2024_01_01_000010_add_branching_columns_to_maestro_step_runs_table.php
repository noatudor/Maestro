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
            $table->string('skip_reason', 50)->nullable()->after('retry_source');
            $table->string('skip_message', 255)->nullable()->after('skip_reason');
            $table->string('branch_key', 255)->nullable()->after('skip_message');

            $table->index(['workflow_id', 'branch_key'], 'idx_step_runs_workflow_branch');
            $table->index(['workflow_id', 'status', 'skip_reason'], 'idx_step_runs_workflow_status_skip');
        });
    }

    public function down(): void
    {
        Schema::table('maestro_step_runs', function (Blueprint $table): void {
            $table->dropIndex('idx_step_runs_workflow_branch');
            $table->dropIndex('idx_step_runs_workflow_status_skip');
            $table->dropColumn(['skip_reason', 'skip_message', 'branch_key']);
        });
    }
};
