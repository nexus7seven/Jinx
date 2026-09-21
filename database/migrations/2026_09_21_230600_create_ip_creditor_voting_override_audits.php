<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The first production attempt may have left the table behind if MySQL
        // failed while adding one of the generated foreign-key names. Because
        // the migration is not recorded until up() completes, it is safe to
        // rebuild this brand-new audit table on retry.
        Schema::dropIfExists('ip_creditor_voting_override_audits');

        Schema::create('ip_creditor_voting_override_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creditor_id');
            $table->foreign('creditor_id', 'ip_vote_audit_creditor_fk')->references('id')->on('creditors')->cascadeOnDelete();
            $table->string('ip_key', 40)->index();
            $table->string('action', 20);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('source', 40)->default('assistant_chat');
            $table->text('source_message')->nullable();
            $table->unsignedBigInteger('assistant_conversation_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();

            $table->foreign('assistant_conversation_id', 'ip_vote_audit_conversation_fk')->references('id')->on('assistant_conversations')->nullOnDelete();
            $table->foreign('lead_id', 'ip_vote_audit_lead_fk')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('changed_by', 'ip_vote_audit_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['creditor_id', 'ip_key', 'created_at'], 'ip_voting_override_audit_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_creditor_voting_override_audits');
    }
};
