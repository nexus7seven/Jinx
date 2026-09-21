<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_creditor_voting_override_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creditor_id')->constrained('creditors')->cascadeOnDelete();
            $table->string('ip_key', 40)->index();
            $table->string('action', 20);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('source', 40)->default('assistant_chat');
            $table->text('source_message')->nullable();
            $table->foreignId('assistant_conversation_id')->nullable()->constrained('assistant_conversations')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['creditor_id', 'ip_key', 'created_at'], 'ip_voting_override_audit_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_creditor_voting_override_audits');
    }
};
