<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE_NAME = 'resources/assistant/knowledge/avondale/00_partner_baseline.md';

    public function up(): void
    {
        $text = "For Avondale cases, the applicable SFS-controlled sections must be built to 65% of the relevant SFS maximum guideline. This applies across Avondale's IP destinations unless a later confirmed IP-specific rule explicitly changes the treatment.";

        $sourceId = DB::table('decision_rule_sources')->insertGetId([
            'source_type'=>'internal_markdown',
            'name'=>self::SOURCE_NAME,
            'version'=>'2026-09-19',
            'sheet'=>'I&E SFS Rule',
            'location'=>'Avondale Partner Baseline',
            'original_text'=>$text,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        DB::table('decision_rules')->insert([
            'scope_type'=>'partner',
            'partner_key'=>'avondale',
            'category'=>'ie',
            'rule_key'=>'avondale_sfs_65_percent',
            'requirement_text'=>$text,
            'severity'=>'requirement',
            'structured_effect'=>json_encode([
                'sfs_percent_of_max'=>65,
                'sections'=>['housekeeping','comms','personal'],
                'precedence'=>['ip','partner','company'],
            ]),
            'source_id'=>$sourceId,
            'is_active'=>true,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    public function down(): void
    {
        $ids=DB::table('decision_rule_sources')
            ->where('source_type','internal_markdown')
            ->where('name',self::SOURCE_NAME)
            ->pluck('id');

        DB::table('decision_rules')->whereIn('source_id',$ids)->delete();
        DB::table('decision_rule_sources')->whereIn('id',$ids)->delete();
    }
};
