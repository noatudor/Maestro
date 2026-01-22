<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_trigger_payloads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('trigger_key', 255);
            $table->longText('payload');
            $table->timestamp('received_at');
            $table->string('source_ip', 45)->nullable();
            $table->string('source_identifier', 255)->nullable();
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_trigger_payloads_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->onDelete('cascade');

            $table->index('workflow_id', 'idx_trigger_payloads_workflow');
            $table->index(['workflow_id', 'trigger_key'], 'idx_trigger_payloads_workflow_key');
            $table->index('received_at', 'idx_trigger_payloads_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_trigger_payloads');
    }
};
