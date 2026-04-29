<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('employment_status')->nullable()->after('estimated_total_debt');
            $table->decimal('monthly_income', 10, 2)->nullable()->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'employment_status',
                'monthly_income',
            ]);
        });
    }
};
