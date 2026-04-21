<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_browser_jobs', function (Blueprint $table) {
            $table->string('awaiting_input_type')->nullable()->after('status');
            $table->longText('awaiting_input_payload')->nullable()->after('result_json');
            $table->longText('provided_input_payload')->nullable()->after('awaiting_input_payload');
            $table->string('current_step')->nullable()->after('heartbeat_at');
            $table->string('progress_message')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('local_browser_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'awaiting_input_type',
                'awaiting_input_payload',
                'provided_input_payload',
                'current_step',
                'progress_message',
            ]);
        });
    }
};
