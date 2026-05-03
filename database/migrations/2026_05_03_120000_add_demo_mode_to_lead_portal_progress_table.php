<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_portal_progress', function (Blueprint $table) {
            $table->boolean('is_demo_mode')->default(false)->after('lead_id');
            $table->json('demo_payload')->nullable()->after('credit_check_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('lead_portal_progress', function (Blueprint $table) {
            $table->dropColumn(['is_demo_mode', 'demo_payload']);
        });
    }
};
