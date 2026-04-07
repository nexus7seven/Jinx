<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('temp_mail')->nullable()->after('email');
            $table->string('temp_mail_provider')->nullable()->after('temp_mail');
            $table->timestamp('temp_mail_created_at')->nullable()->after('temp_mail_provider');
            $table->timestamp('temp_mail_last_checked_at')->nullable()->after('temp_mail_created_at');
            $table->string('temp_mail_last_code', 100)->nullable()->after('temp_mail_last_checked_at');
            $table->string('temp_mail_last_subject')->nullable()->after('temp_mail_last_code');
            $table->text('temp_mail_last_from')->nullable()->after('temp_mail_last_subject');
            $table->string('temp_mail_last_message_id')->nullable()->after('temp_mail_last_from');
            $table->longText('temp_mail_last_body_text')->nullable()->after('temp_mail_last_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'temp_mail',
                'temp_mail_provider',
                'temp_mail_created_at',
                'temp_mail_last_checked_at',
                'temp_mail_last_code',
                'temp_mail_last_subject',
                'temp_mail_last_from',
                'temp_mail_last_message_id',
                'temp_mail_last_body_text',
            ]);
        });
    }
};