<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->decimal('monthly_housing_cost', 10, 2)->nullable()->after('monthly_income');
            $table->decimal('monthly_council_tax', 10, 2)->nullable()->after('monthly_housing_cost');
            $table->decimal('monthly_utilities_cost', 10, 2)->nullable()->after('monthly_council_tax');
            $table->decimal('monthly_food_travel_cost', 10, 2)->nullable()->after('monthly_utilities_cost');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_housing_cost',
                'monthly_council_tax',
                'monthly_utilities_cost',
                'monthly_food_travel_cost',
            ]);
        });
    }
};
