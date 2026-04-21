<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_browser_job_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('local_browser_job_id')->index();
            $table->string('type')->index();
            $table->string('original_name')->nullable();
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->longText('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_browser_job_artifacts');
    }
};
