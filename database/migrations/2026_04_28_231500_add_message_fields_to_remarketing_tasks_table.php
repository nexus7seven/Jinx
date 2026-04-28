<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('remarketing_tasks')) {
            return;
        }

        Schema::table('remarketing_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('remarketing_tasks', 'message_body')) {
                $table->longText('message_body')->nullable()->after('whatsapp_url');
            }
            if (! Schema::hasColumn('remarketing_tasks', 'metadata_json')) {
                $table->json('metadata_json')->nullable()->after('message_body');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('remarketing_tasks')) {
            return;
        }

        Schema::table('remarketing_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('remarketing_tasks', 'metadata_json')) {
                $table->dropColumn('metadata_json');
            }
            if (Schema::hasColumn('remarketing_tasks', 'message_body')) {
                $table->dropColumn('message_body');
            }
        });
    }
};
