<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_reengagement_events', function (Blueprint $table) {
            $table->dateTime('seen_at')->nullable()->after('engagement_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_reengagement_events', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
