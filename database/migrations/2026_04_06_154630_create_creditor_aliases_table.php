<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditor_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creditor_id')->constrained('creditors')->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->timestamps();

            $table->unique(['creditor_id', 'normalized_alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creditor_aliases');
    }
};