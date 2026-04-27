<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_remarketing_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->foreignId('current_step_id')->nullable()->constrained('remarketing_steps')->nullOnDelete();
            $table->unsignedInteger('current_step_order')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_step_completed_at')->nullable();
            $table->timestamp('next_step_due_at')->nullable()->index();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->json('stop_context_json')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_step_due_at']);
            $table->index(['lead_id', 'status']);
            // TODO: If single active cycle is confirmed long-term, add a unique active-row strategy on lead_id.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_remarketing_progress');
    }
};
