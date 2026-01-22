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
            $table->timestamp('compensation_started_at')->nullable()->after('next_auto_retry_at');
            $table->timestamp('compensated_at')->nullable()->after('compensation_started_at');

            $table->index(['state', 'compensation_started_at'], 'idx_workflows_state_compensation');
        });
    }

    public function down(): void
    {
        Schema::table('maestro_workflows', function (Blueprint $table): void {
            $table->dropIndex('idx_workflows_state_compensation');
            $table->dropColumn(['compensation_started_at', 'compensated_at']);
        });
    }
};
