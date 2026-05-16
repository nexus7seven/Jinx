<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('portal_email')->nullable()->unique()->after('token');
            $table->string('portal_password')->nullable()->after('portal_email');
            $table->boolean('portal_access_enabled')->default(false)->after('portal_password');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['portal_email', 'portal_password', 'portal_access_enabled']);
        });
    }
};
