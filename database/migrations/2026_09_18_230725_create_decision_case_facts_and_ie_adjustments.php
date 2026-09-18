<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_decision_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('fact_key', 120);
            $table->json('value_json')->nullable();
            $table->string('source_type', 40)->default('operator');
            $table->text('source_detail')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->unique(['lead_id','fact_key']);
            $table->index(['fact_key','source_type']);
        });

        Schema::create('debt_decision_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->string('fact_key', 120);
            $table->json('value_json')->nullable();
            $table->string('source_type', 40)->default('operator');
            $table->text('source_detail')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->unique(['debt_id','fact_key']);
            $table->index(['fact_key','source_type']);
        });

        Schema::create('lead_ie_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 160);
            $table->decimal('original_amount', 12, 2);
            $table->decimal('proposed_amount', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->text('reason');
            $table->foreignId('rule_id')->nullable()->constrained('decision_rules')->nullOnDelete();
            $table->text('evidence')->nullable();
            $table->string('status', 30)->default('proposed');
            $table->string('actor', 60)->default('jinx_agent');
            $table->timestamps();
            $table->index(['lead_id','status']);
        });

        Schema::create('lead_decision_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('assessment_type', 40);
            $table->string('preferred_route')->nullable();
            $table->string('status', 50)->nullable();
            $table->json('result_json');
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['lead_id','assessment_type','assessed_at'],'decision_assessment_lead_type_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_decision_assessments');
        Schema::dropIfExists('lead_ie_adjustments');
        Schema::dropIfExists('debt_decision_facts');
        Schema::dropIfExists('lead_decision_facts');
    }
};
