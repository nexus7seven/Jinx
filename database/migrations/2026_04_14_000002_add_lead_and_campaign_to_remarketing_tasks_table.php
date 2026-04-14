<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remarketing_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable()->after('id');
            $table->string('campaign_id')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('remarketing_tasks', function (Blueprint $table) {
            $table->dropColumn(['lead_id', 'campaign_id']);
        });
    }
};
