<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('portal_credit_check_started_at')->nullable()->after('financial_statement');
            $table->timestamp('portal_credit_check_completed_at')->nullable()->after('portal_credit_check_started_at');
            $table->timestamp('portal_credit_check_last_run_at')->nullable()->after('portal_credit_check_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'portal_credit_check_started_at',
                'portal_credit_check_completed_at',
                'portal_credit_check_last_run_at',
            ]);
        });
    }
};
