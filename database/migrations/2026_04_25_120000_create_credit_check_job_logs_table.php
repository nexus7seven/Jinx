<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_check_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('local_worker_job_id')->nullable()->index();
            $table->string('external_job_id', 191)->nullable()->index();
            $table->foreignId('invoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->nullable()->index();
            $table->string('friendly_status', 191)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->longText('raw_log')->nullable();
            $table->string('saved_pdf_path', 2048)->nullable();
            $table->json('result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_check_job_logs');
    }
};
