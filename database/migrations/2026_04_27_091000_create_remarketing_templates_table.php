<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remarketing_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key')->unique();
            $table->string('template_name');
            $table->string('medium')->index();
            $table->string('provider')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->string('external_template_id')->nullable();
            $table->json('variables_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index(['medium', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remarketing_templates');
    }
};
