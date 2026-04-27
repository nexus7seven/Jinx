<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remarketing_response_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('jinx_lead_id')->nullable()->index();
            $table->unsignedBigInteger('remarketing_progress_id')->nullable()->index();
            $table->string('source_event_id')->nullable()->index();
            $table->string('dedupe_key')->unique();
            $table->string('channel')->index();
            $table->string('direction')->default('inbound')->index();
            $table->string('status')->default('needs_review')->index();
            $table->string('matched_phone')->nullable();
            $table->string('matched_email')->nullable();
            $table->text('message_preview')->nullable();
            $table->json('raw_payload_json')->nullable();
            $table->timestamp('detected_at')->nullable()->index();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->string('decision')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'status']);
            $table->index(['channel', 'status']);
            $table->index(['status', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remarketing_response_events');
    }
};
