<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wip_case_queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('position')->default(0);
            $table->string('waiting_on', 64)->nullable();
            $table->timestamp('next_chase_at')->nullable();
            $table->timestamp('last_actioned_at')->nullable();
            $table->text('action_note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lead_id']);
            $table->index(['user_id', 'position']);
            $table->index(['user_id', 'next_chase_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wip_case_queue_items');
    }
};
