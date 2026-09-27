<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('bot_case_trackers', function(Blueprint $t){$t->unsignedBigInteger('lead_id')->primary();$t->string('bot',32)->default('Pacman');$t->string('ip_route',50)->nullable();$t->json('obtained');$t->json('missing');$t->text('summary')->nullable();$t->text('next_action')->nullable();$t->string('state',50)->default('reviewing');$t->string('source_revision',64)->nullable();$t->timestamps();$t->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();}); }
 public function down(): void { Schema::dropIfExists('bot_case_trackers'); }
};
