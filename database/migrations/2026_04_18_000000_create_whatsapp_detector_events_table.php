<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_detector_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('chat_id')->nullable();
            $table->string('chat_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('preview_time_text')->nullable();
            // DATETIME: avoids MySQL TIMESTAMP session/UTC conversion quirks; wider range than TIMESTAMP on older MySQL.
            $table->dateTime('inferred_message_at')->nullable();
            $table->dateTime('last_inbound_at')->nullable();
            $table->text('latest_message')->nullable();
            $table->dateTime('detected_at')->nullable();
            $table->dateTime('batch_written_at')->nullable();
            $table->unsignedBigInteger('matched_vicidial_lead_id')->nullable();
            $table->dateTime('flow_started_at')->nullable();
            $table->dateTime('engagement_at')->nullable();
            $table->boolean('is_after_flow_start')->nullable();
            $table->string('match_status')->nullable();
            $table->text('notes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_detector_events');
    }
};
