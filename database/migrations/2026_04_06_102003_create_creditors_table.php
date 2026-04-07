<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('voting_house');
            $table->string('voting_practice1')->default('none');
            $table->string('voting_practice2')->default('none');
            $table->string('voting_practice3')->default('none');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creditors');
    }
};