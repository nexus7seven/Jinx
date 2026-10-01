<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bot_case_trackers', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_stage')->nullable()->after('state');
            $table->json('stages')->nullable()->after('current_stage');
        });
    }

    public function down(): void
    {
        Schema::table('bot_case_trackers', function (Blueprint $table) {
            $table->dropColumn(['current_stage', 'stages']);
        });
    }
};
