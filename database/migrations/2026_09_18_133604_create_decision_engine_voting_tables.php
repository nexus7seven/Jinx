<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_rule_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 40);
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('sheet')->nullable();
            $table->string('location')->nullable();
            $table->text('original_text')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'name']);
        });

        Schema::create('creditor_voting_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creditor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voting_house_id')->nullable()->constrained('voting_houses')->nullOnDelete();
            $table->string('partner_key')->nullable();
            $table->string('ip_key')->nullable();
            $table->string('debt_type')->nullable();
            $table->text('condition_text')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('source_id')->nullable()->constrained('decision_rule_sources')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['creditor_id', 'is_active']);
            $table->index(['partner_key', 'ip_key']);
        });

        Schema::create('decision_rules', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 40);
            $table->foreignId('voting_house_id')->nullable()->constrained('voting_houses')->nullOnDelete();
            $table->foreignId('creditor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_key')->nullable();
            $table->string('ip_key')->nullable();
            $table->string('category', 80);
            $table->string('rule_key')->nullable();
            $table->text('condition_text')->nullable();
            $table->text('requirement_text');
            $table->text('consequence_text')->nullable();
            $table->string('severity', 40)->default('unknown');
            $table->json('structured_condition')->nullable();
            $table->json('structured_effect')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('decision_rule_sources')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'category']);
            $table->index(['voting_house_id', 'creditor_id']);
            $table->index(['partner_key', 'ip_key']);
        });

        Schema::create('lead_voting_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('partner_key')->nullable();
            $table->string('ip_key')->nullable();
            $table->decimal('qualifying_debt_total', 12, 2)->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['lead_id', 'assessed_at']);
        });

        Schema::create('lead_voting_snapshot_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('lead_voting_snapshots')->cascadeOnDelete();
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creditor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voting_house_id')->nullable()->constrained('voting_houses')->nullOnDelete();
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('voting_percent', 7, 4)->default(0);
            $table->json('applicable_rule_ids')->nullable();
            $table->json('assessment')->nullable();
            $table->timestamps();
            $table->index(['snapshot_id', 'voting_house_id']);
        });

        Schema::create('decision_learning_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('knowledge_type', 40);
            $table->string('status', 30)->default('active');
            $table->string('original_decision')->nullable();
            $table->string('corrected_decision')->nullable();
            $table->text('reason');
            $table->json('case_context')->nullable();
            $table->json('applicability')->nullable();
            $table->json('related_rule_ids')->nullable();
            $table->string('outcome')->nullable();
            $table->unsignedInteger('times_confirmed')->default(1);
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['knowledge_type', 'status']);
            $table->index(['lead_id', 'status']);
        });

        // Preserve the old hybrid creditor fields, but seed the new unlimited route model from them.
        $houses = DB::table('voting_houses')->pluck('id', 'key');
        DB::table('creditors')->orderBy('id')->get()->each(function ($creditor) use ($houses) {
            $key = trim((string) $creditor->voting_house);
            if ($key === '') return;
            $houseId = $houses[$key] ?? DB::table('voting_houses')->insertGetId([
                'key' => $key, 'rules_text' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('creditor_voting_routes')->insert([
                'creditor_id' => $creditor->id,
                'voting_house_id' => $houseId,
                'condition_text' => 'Migrated from creditors.voting_house legacy field',
                'priority' => 100,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_learning_records');
        Schema::dropIfExists('lead_voting_snapshot_debts');
        Schema::dropIfExists('lead_voting_snapshots');
        Schema::dropIfExists('decision_rules');
        Schema::dropIfExists('creditor_voting_routes');
        Schema::dropIfExists('decision_rule_sources');
    }
};
