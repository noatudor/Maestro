<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maestro_workflows', function (Blueprint $table): void {
            $table->string('awaiting_trigger_key', 255)->nullable()->after('compensated_at');
            $table->timestamp('trigger_timeout_at')->nullable()->after('awaiting_trigger_key');
            $table->timestamp('trigger_registered_at')->nullable()->after('trigger_timeout_at');
            $table->timestamp('scheduled_resume_at')->nullable()->after('trigger_registered_at');

            $table->index('awaiting_trigger_key', 'idx_workflows_awaiting_trigger');
            $table->index(['state', 'trigger_timeout_at'], 'idx_workflows_state_trigger_timeout');
            $table->index(['state', 'scheduled_resume_at'], 'idx_workflows_state_scheduled_resume');
        });
    }

    public function down(): void
    {
        Schema::table('maestro_workflows', function (Blueprint $table): void {
            $table->dropIndex('idx_workflows_awaiting_trigger');
            $table->dropIndex('idx_workflows_state_trigger_timeout');
            $table->dropIndex('idx_workflows_state_scheduled_resume');
            $table->dropColumn([
                'awaiting_trigger_key',
                'trigger_timeout_at',
                'trigger_registered_at',
                'scheduled_resume_at',
            ]);
        });
    }
};
