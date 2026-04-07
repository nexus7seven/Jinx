<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('item_name');
            $table->boolean('is_complete')->default(false);
            $table->timestamps();

            $table->index(['lead_id', 'is_complete']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_checklist_items');
    }
};