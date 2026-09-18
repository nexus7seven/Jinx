<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $exit = Artisan::call('jinx:import-decision-creditors', [
            'file' => 'resources/assistant/decision-engine/creditor-intelligence-full.json',
        ]);

        if ($exit !== 0) {
            throw new \RuntimeException('Decision-engine workbook import failed: '.Artisan::output());
        }
    }

    public function down(): void
    {
        $sourceIds = DB::table('decision_rule_sources')
            ->where('source_type', 'workbook_decision_engine')
            ->pluck('id');

        DB::table('creditor_voting_routes')->whereIn('source_id', $sourceIds)->delete();
        DB::table('decision_rules')->whereIn('source_id', $sourceIds)->delete();
        DB::table('decision_rule_sources')->whereIn('id', $sourceIds)->delete();
        DB::table('decision_creditor_source_rows')->delete();
    }
};
