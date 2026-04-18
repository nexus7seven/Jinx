<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_reengagement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('vicidial_lead_id')->nullable();
            $table->foreignId('whatsapp_detector_event_id')->nullable()->constrained('whatsapp_detector_events')->nullOnDelete();
            $table->string('whatsapp_detector_event_uuid')->nullable();
            $table->string('channel')->default('whatsapp');
            $table->string('remarketing_stage')->nullable();
            $table->string('remarketing_task_type')->nullable();
            $table->text('remarketing_reason')->nullable();
            $table->dateTime('flow_started_at')->nullable();
            $table->dateTime('engagement_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_reengagement_events');
    }
};
