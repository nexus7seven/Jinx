<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_portal_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('creditor_name')->nullable();
            $table->decimal('balance', 10, 2)->nullable();
            $table->string('source')->default('portal');
            $table->timestamps();

            $table->index('lead_id');
            $table->index(['lead_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_portal_debts');
    }
};
