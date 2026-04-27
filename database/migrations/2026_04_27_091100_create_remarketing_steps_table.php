<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remarketing_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('step_order')->index();
            $table->string('step_key')->unique();
            $table->string('step_name');
            $table->string('medium')->index();
            $table->foreignId('template_id')->nullable()->constrained('remarketing_templates')->nullOnDelete();
            $table->string('template_name')->nullable();
            $table->string('template_variable')->nullable();
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->boolean('requires_manual_completion')->default(false);
            $table->boolean('auto_advance_on_send')->default(true);
            $table->boolean('respect_send_window')->default(true);
            $table->time('send_window_start_time')->default('09:00:00');
            $table->time('send_window_end_time')->default('21:00:00');
            $table->json('allowed_days_json')->nullable();
            $table->boolean('stop_if_replied')->default(true);
            $table->boolean('stop_if_converted')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('step_date_added')->nullable();
            $table->timestamp('step_date_modified')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index(['medium', 'is_active']);
            $table->index(['step_order', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remarketing_steps');
    }
};
