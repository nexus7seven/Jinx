<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_creditor_voting_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_creditor_id')->constrained('creditors')->cascadeOnDelete();
            $table->foreignId('target_creditor_id')->constrained('creditors')->cascadeOnDelete();
            $table->string('ip_key', 40);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_from_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_creditor_id', 'ip_key'], 'ip_creditor_voting_match_unique');
            $table->index(['target_creditor_id', 'ip_key'], 'ip_creditor_voting_match_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_creditor_voting_matches');
    }
};
