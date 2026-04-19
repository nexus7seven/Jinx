<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('middle_name', 120)->nullable()->after('first_name');
            $table->string('house_name', 120)->nullable()->after('house_number');
            $table->string('building_number', 50)->nullable()->after('house_name');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['middle_name', 'house_name', 'building_number']);
        });
    }
};
