<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_portal_email_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('lead_portal_snapshot_id')->nullable()->constrained('lead_portal_snapshots')->nullOnDelete();
            $table->string('click_type')->index();
            $table->text('destination_url');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('clicked_at');
            $table->json('raw_context_json')->nullable();
            $table->timestamps();

            $table->index('lead_id');
            $table->index('lead_portal_snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_portal_email_clicks');
    }
};
