<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('iva_ip_key', 40)->nullable()->after('wip_status')->index();
        });

        Schema::create('ip_creditor_voting_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creditor_id')->constrained()->cascadeOnDelete();
            $table->string('ip_key', 40);
            $table->string('status_text', 120);
            $table->string('outcome', 40)->default('unknown');
            $table->string('voting_house', 120)->nullable();
            $table->text('condition_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['creditor_id', 'ip_key'], 'ip_creditor_voting_override_unique');
            $table->index(['ip_key', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_creditor_voting_overrides');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['iva_ip_key']);
            $table->dropColumn('iva_ip_key');
        });
    }
};
