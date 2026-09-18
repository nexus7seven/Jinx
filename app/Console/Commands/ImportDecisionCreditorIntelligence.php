<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportDecisionCreditorIntelligence extends Command
{
    protected $signature = 'jinx:import-decision-creditors {file=resources/assistant/decision-engine/creditor-intelligence.json}';
    protected $description = 'Import sourced creditor rules and representative mappings into the IVA decision engine';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));
        if (!is_file($path)) { $this->error("Missing source file: {$path}"); return self::FAILURE; }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $creditors = DB::table('creditors')->get(['id','name']);
        $aliases = DB::table('creditor_aliases')->get(['creditor_id','alias']);
        $map = [];
        foreach ($creditors as $c) $map[$this->norm($c->name)] = ['id'=>$c->id,'method'=>'creditor_name'];
        foreach ($aliases as $a) $map[$this->norm($a->alias)] ??= ['id'=>$a->creditor_id,'method'=>'alias'];
        $houses = DB::table('voting_houses')->get()->mapWithKeys(fn($h)=>[strtolower($h->key)=>$h->id]);
        foreach (['WATCH','TIX','Evolve'] as $h) if (!$houses->has(strtolower($h))) {
            $houses[strtolower($h)] = DB::table('voting_houses')->insertGetId(['key'=>$h,'created_at'=>now(),'updated_at'=>now()]);
        }

        $stats=['rows'=>0,'matched'=>0,'unmatched'=>0,'rules'=>0,'routes'=>0];
        DB::transaction(function() use ($data,$map,$houses,&$stats) {
            $oldSources=DB::table('decision_rule_sources')->where('source_type','workbook_creditor_intelligence')->pluck('id');
            DB::table('creditor_voting_routes')->whereIn('source_id',$oldSources)->delete();
            DB::table('decision_rules')->whereIn('source_id',$oldSources)->delete();
            DB::table('decision_rule_sources')->whereIn('id',$oldSources)->delete();
            DB::table('decision_creditor_source_rows')->delete();

            foreach ($data['creditor_rules'] as $r) {
                $match=$map[$this->norm($r['name'])]??null;
                $rowId=$this->sourceRow($r,$match,null,null); $stats['rows']++; $match?$stats['matched']++:$stats['unmatched']++;
                if (!$match) continue;
                $text=trim(implode(' | ',array_values(array_filter([$r['status']??null,$r['detail']??null]))));
                if ($text==='') continue;
                $source=$this->ruleSource($r,$text);
                DB::table('decision_rules')->insert([
                    'scope_type'=>'creditor','creditor_id'=>$match['id'],'partner_key'=>$r['partner'],
                    'category'=>$r['sheet']==='Dividends ' ? 'dividend' : 'creditor_specific',
                    'requirement_text'=>$text,'severity'=>$this->severity($text),'source_id'=>$source,
                    'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
                ]); $stats['rules']++;
                if ($house=$this->houseFromText($text)) {
                    $this->route($match['id'],$houses[strtolower($house)],$r['partner'],$source,'Inferred from creditor workbook wording: '.$text);
                    $stats['routes']++;
                }
            }

            foreach ($data['explicit_routes'] as $r) {
                $match=$map[$this->norm($r['name'])]??null;
                $this->sourceRow($r,$match,$r['house'],null); $stats['rows']++; $match?$stats['matched']++:$stats['unmatched']++;
                if (!$match) continue;
                $text=$r['name'].' is listed under '.$r['house'].' in '.$r['sheet'];
                $source=$this->ruleSource($r,$text);
                $this->route($match['id'],$houses[strtolower($r['house'])],$r['partner'],$source,$text);
                $stats['routes']++;
            }

            foreach ($data['representative_catalog'] as $r) {
                $match=$map[$this->norm($r['name'])]??null;
                $this->sourceRow($r,$match,$r['house'],$r['data']??null); $stats['rows']++; $match?$stats['matched']++:$stats['unmatched']++;
            }
        });
        $this->info(json_encode($stats));
        return self::SUCCESS;
    }

    private function norm(?string $s): string {
        $s=mb_strtolower(trim((string)$s)); $s=str_replace(['&','–','—'],['and','-','-'],$s);
        return preg_replace('/[^a-z0-9]+/','',$s) ?? '';
    }
    private function severity(string $t): string {
        $x=strtolower($t);
        if (str_contains($x,'reject')) return 'hard_or_conditional_reject';
        if (str_contains($x,'do not vote')||str_contains($x,'non-voting')) return 'non_voting';
        if (str_contains($x,'condition')||str_contains($x,'consider')||str_contains($x,'trial')||str_contains($x,'moc')) return 'conditional_or_escalation';
        return 'requirement';
    }
    private function houseFromText(string $t): ?string {
        $x=strtolower($t);
        if (str_contains($x,'watch portfolio')||preg_match('/\\bwatch\\b/',$x)) return 'WATCH';
        if (preg_match('/\\btix\\b/',$x)) return 'TIX';
        if (preg_match('/\\bevolve\\b/',$x)) return 'Evolve';
        return null;
    }

    private function sourceRow(array $r, ?array $match, ?string $house, ?array $data): int {
        return DB::table('decision_creditor_source_rows')->insertGetId([
            'source_name'=>$r['source'],'sheet'=>$r['sheet'],'source_row'=>$r['row'],'partner_key'=>$r['partner']??null,
            'representative_key'=>$house,'creditor_name_raw'=>$r['name'],'creditor_id'=>$match['id']??null,
            'match_method'=>$match['method']??null,'status_text'=>$r['status']??null,'detail_text'=>$r['detail']??null,
            'source_data'=>$data?json_encode($data):null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    private function ruleSource(array $r,string $text): int {
        return DB::table('decision_rule_sources')->insertGetId([
            'source_type'=>'workbook_creditor_intelligence','name'=>$r['source'],'sheet'=>$r['sheet'],
            'location'=>'Row '.$r['row'],'original_text'=>$text,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    private function route(int $creditorId,int $houseId,?string $partner,int $source,string $condition): void {
        $exists=DB::table('creditor_voting_routes')->where('creditor_id',$creditorId)->where('voting_house_id',$houseId)
            ->where('partner_key',$partner)->where('is_active',true)->exists();
        if ($exists) return;
        DB::table('creditor_voting_routes')->insert([
            'creditor_id'=>$creditorId,'voting_house_id'=>$houseId,'partner_key'=>$partner,'condition_text'=>$condition,
            'priority'=>20,'is_default'=>false,'is_active'=>true,'source_id'=>$source,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
