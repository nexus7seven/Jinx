<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remarketing_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('lead_name');
            $table->string('phone');
            $table->string('task_type');
            $table->string('reason');
            $table->string('stage');
            $table->string('status');
            $table->string('time_waiting_text')->nullable();
            $table->text('whatsapp_url')->nullable();
            $table->timestamps();

            $table->index(['status', 'stage']);
            $table->index(['lead_name', 'task_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remarketing_tasks');
    }
};
