<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('single_stage_submission')->default(false)->after('active');
            $table->boolean('minimal_submission_form')->default(false)->after('single_stage_submission');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['single_stage_submission', 'minimal_submission_form']);
        });
    }
};
