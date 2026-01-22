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
            $table->unsignedInteger('poll_attempt_count')->default(0)->after('branch_key');
            $table->timestamp('next_poll_at')->nullable()->after('poll_attempt_count');
            $table->timestamp('poll_started_at')->nullable()->after('next_poll_at');

            $table->index(['status', 'next_poll_at'], 'idx_step_runs_polling_due');
            $table->index(['workflow_id', 'status', 'next_poll_at'], 'idx_step_runs_workflow_polling');
        });
    }

    public function down(): void
    {
        Schema::table('maestro_step_runs', function (Blueprint $table): void {
            $table->dropIndex('idx_step_runs_polling_due');
            $table->dropIndex('idx_step_runs_workflow_polling');
            $table->dropColumn(['poll_attempt_count', 'next_poll_at', 'poll_started_at']);
        });
    }
};
