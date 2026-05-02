<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_portal_progress', function (Blueprint $table) {
            $table->unsignedTinyInteger('credit_check_attempts')->default(0)->after('last_completed_step');
        });
    }

    public function down(): void
    {
        Schema::table('lead_portal_progress', function (Blueprint $table) {
            $table->dropColumn('credit_check_attempts');
        });
    }
};
