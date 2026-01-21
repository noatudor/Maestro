<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maestro_step_outputs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('step_key', 255);
            $table->string('output_class', 500);
            $table->binary('payload');
            $table->timestamps();

            $table->foreign('workflow_id', 'fk_step_outputs_workflow')
                ->references('id')
                ->on('maestro_workflows')
                ->onDelete('cascade');

            $table->unique(['workflow_id', 'output_class'], 'uq_step_outputs_workflow_class');
            $table->index('workflow_id', 'idx_step_outputs_workflow');
            $table->index('step_key', 'idx_step_outputs_step_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maestro_step_outputs');
    }
};
