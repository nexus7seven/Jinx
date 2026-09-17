<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('creditors', function (Blueprint $table) { $table->string('contact_phone')->nullable()->after('name'); $table->string('contact_hours')->nullable()->after('contact_phone'); $table->text('contact_notes')->nullable()->after('contact_hours'); }); }
    public function down(): void { Schema::table('creditors', function (Blueprint $table) { $table->dropColumn(['contact_phone','contact_hours','contact_notes']); }); }
};
