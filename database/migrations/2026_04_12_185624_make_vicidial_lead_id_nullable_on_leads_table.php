<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE leads MODIFY vicidial_lead_id BIGINT UNSIGNED NULL');
        }

        // SQLite (e.g. phpunit :memory:) does not support MODIFY; tests use the original leads schema.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE leads MODIFY vicidial_lead_id BIGINT UNSIGNED NOT NULL');
        }
    }
};