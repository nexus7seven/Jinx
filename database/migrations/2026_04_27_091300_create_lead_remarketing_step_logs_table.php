<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_remarketing_step_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->foreignId('remarketing_step_id')->nullable()->constrained('remarketing_steps')->nullOnDelete();
            $table->unsignedInteger('step_order')->nullable()->index();
            $table->string('medium')->nullable()->index();
            $table->foreignId('template_id')->nullable()->constrained('remarketing_templates')->nullOnDelete();
            $table->string('status')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_task_id')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'status']);
            $table->index(['lead_id', 'remarketing_step_id']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_remarketing_step_logs');
    }
};
