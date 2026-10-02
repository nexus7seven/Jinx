<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('sip_invoice_ip', 40)->nullable()->after('sip_booked_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['sip_invoice_ip']);
            $table->dropColumn('sip_invoice_ip');
        });
    }
};
