<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_checklist_items', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('is_complete');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->boolean('is_system')->default(false)->after('source_id');

            $table->index(['lead_id', 'source_type', 'source_id'], 'lead_checklist_items_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lead_checklist_items', function (Blueprint $table) {
            $table->dropIndex('lead_checklist_items_source_idx');
            $table->dropColumn(['source_type', 'source_id', 'is_system']);
        });
    }
};