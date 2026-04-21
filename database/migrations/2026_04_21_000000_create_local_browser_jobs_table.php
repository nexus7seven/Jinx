<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_browser_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('job_type')->index();
            $table->string('status')->default('queued')->index();
            $table->longText('payload_json')->nullable();
            $table->longText('result_json')->nullable();
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->longText('error_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_browser_jobs');
    }
};
