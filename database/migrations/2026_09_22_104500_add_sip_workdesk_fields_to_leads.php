<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dateTime('sip_booked_at')->nullable()->after('wip_status');
            $table->dateTime('sip_prep_completed_at')->nullable()->after('sip_booked_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['sip_booked_at', 'sip_prep_completed_at']);
        });
    }
};
