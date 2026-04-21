<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_browser_jobs', function (Blueprint $table) {
            $table->string('temp_email_address')->nullable()->after('provided_input_payload');
            $table->longText('temp_email_meta_json')->nullable()->after('temp_email_address');
            $table->string('latest_email_code')->nullable()->after('temp_email_meta_json');
        });
    }

    public function down(): void
    {
        Schema::table('local_browser_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'temp_email_address',
                'temp_email_meta_json',
                'latest_email_code',
            ]);
        });
    }
};
