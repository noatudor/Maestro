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
            $table->unsignedInteger('auto_retry_count')->default(0)->after('failure_message');
            $table->timestamp('next_auto_retry_at')->nullable()->after('auto_retry_count');

            $table->index('next_auto_retry_at', 'idx_workflows_next_auto_retry');
            $table->index(['state', 'next_auto_retry_at'], 'idx_workflows_state_auto_retry');
        });
    }

    public function down(): void
    {
        Schema::table('maestro_workflows', function (Blueprint $table): void {
            $table->dropIndex('idx_workflows_next_auto_retry');
            $table->dropIndex('idx_workflows_state_auto_retry');
            $table->dropColumn(['auto_retry_count', 'next_auto_retry_at']);
        });
    }
};
