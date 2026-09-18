<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('decision_creditor_source_rows', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->string('sheet');
            $table->unsignedInteger('source_row');
            $table->string('partner_key')->nullable();
            $table->string('representative_key')->nullable();
            $table->string('creditor_name_raw');
            $table->foreignId('creditor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_method',40)->nullable();
            $table->string('status_text')->nullable();
            $table->text('detail_text')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();
            $table->index(['creditor_id','partner_key']);
            $table->index(['representative_key','partner_key'],'decision_creditor_rep_partner_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('decision_creditor_source_rows'); }
};
