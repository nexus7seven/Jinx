<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jinx_agent_dialler_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->index();
            $table->unsignedBigInteger('vicidial_lead_id')->index();
            $table->string('action', 80)->index();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('jinx_agent_dialler_audits'); }
};
