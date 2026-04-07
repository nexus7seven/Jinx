<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::create('leads', function (Blueprint $table) {
        $table->id();

        $table->string('vicidial_lead_id')->unique();
        $table->string('phone_number', 20)->unique()->nullable();

        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();

        $table->string('house_number')->nullable();
        $table->string('postcode')->nullable();
        $table->string('address_line_1')->nullable();

        $table->string('status')->nullable();
        $table->string('outcome')->nullable();

        $table->timestamps();
    });
}
}
;
