<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportDecisionCreditorIntelligence extends Command
{
    protected $signature = 'jinx:import-decision-creditors {file=resources/assistant/decision-engine/creditor-intelligence-full.json}';
    protected $description = 'Import sourced creditor rules and representative mappings into the IVA decision engine';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));
        if (!is_file($path)) { $this->error("Missing source file: {$path}"); return self::FAILURE; }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        // The source JSON originally used "avondale_ac" for the Avondale AC workbook.
        // AC is Anchorage Chambers, not an Avondale-wide support scope.
        foreach (['creditor_rules','explicit_routes','representative_catalog','representative_rules','general_rules'] as $section) {
            foreach ($data[$section] ?? [] as $i => $row) {
                if (($row['partner'] ?? null) === 'avondale_ac') {
                    $data[$section][$i]['partner'] = 'anchorage_chambers';
                }
            }
        }
        $creditors = DB::table('creditors')->get(['id','name']);
        $aliases = DB::table('creditor_aliases')->get(['creditor_id','alias']);
        $map = [];
        foreach ($creditors as $c) $map[$this->norm($c->name)] = ['id'=>$c->id,'method'=>'creditor_name'];
        foreach ($aliases as $a) $map[$this->norm($a->alias)] ??= ['id'=>$a->creditor_id,'method'=>'alias'];
        $match = function (string $name) use ($map): ?array {
            $keys = [$this->norm($name)];
            $clean = preg_replace('/\\s*[-–—]\\s*(IVA|TD|IVA or TD).*$/i','',$name);
            $clean = preg_replace('/\\s*\\((WATCH|WPM|TIX|EVOLVE)\\)\\s*/i',' ',$clean ?? $name);
            $keys[] = $this->norm($clean);
            foreach (array_unique($keys) as $key) if ($key !== '' && isset($map[$key])) {
                $m=$map[$key]; $m['method']=$key===$keys[0]?$m['method']:$m['method'].'_cleaned'; return $m;
            }
            return null;
        };
        $houses = DB::table('voting_houses')->get()->mapWithKeys(fn($h)=>[strtolower($h->key)=>$h->id]);
        $houseNames = collect($data['explicit_routes'] ?? [])->pluck('house')
            ->merge(collect($data['representative_catalog'] ?? [])->pluck('house'))
            ->merge(collect($data['representative_rules'] ?? [])->pluck('house'))
            ->filter()->unique();
        foreach ($houseNames as $h) if (!$houses->has(strtolower($h))) {
            $houses[strtolower($h)] = DB::table('voting_houses')->insertGetId(['key'=>$h,'created_at'=>now(),'updated_at'=>now()]);
        }

        $ignoredNames = collect([
            'Laser at The Insolvency Exchange',
            'Mercer & Hughes Veterinary Surgeons',
        ])->map(fn($x)=>$this->norm($x))->all();
        $isIgnored = fn(string $name): bool => in_array($this->norm($name), $ignoredNames, true);

        $stats=['rows'=>0,'matched'=>0,'unmatched'=>0,'ignored'=>0,'rules'=>0,'routes'=>0,'representative_rules'=>0,'general_rules'=>0];
        DB::transaction(function() use ($data,$match,$isIgnored,$houses,&$stats) {
            $oldSources=DB::table('decision_rule_sources')->whereIn('source_type',['workbook_creditor_intelligence','workbook_decision_engine'])->pluck('id');
            DB::table('creditor_voting_routes')->whereIn('source_id',$oldSources)->delete();
            DB::table('decision_rules')->whereIn('source_id',$oldSources)->delete();
            DB::table('decision_rule_sources')->whereIn('id',$oldSources)->delete();
            DB::table('decision_creditor_source_rows')->delete();

            foreach ($data['creditor_rules'] as $r) {
                if ($isIgnored($r['name'])) {
                    $this->sourceRow($r,['id'=>null,'method'=>'ignored_by_operator'],null,null);
                    $stats['rows']++; $stats['ignored']++; continue;
                }
                $matched=$match($r['name']);
                $rowId=$this->sourceRow($r,$matched,null,null); $stats['rows']++; $matched?$stats['matched']++:$stats['unmatched']++;
                if (!$matched) continue;
                $text=trim(implode(' | ',array_values(array_filter([$r['status']??null,$r['detail']??null]))));
                if ($text==='') continue;
                $source=$this->ruleSource($r,$text);
                DB::table('decision_rules')->insert([
                    'scope_type'=>'creditor','creditor_id'=>$matched['id'],'partner_key'=>$r['partner'],
                    'category'=>$this->category($r['sheet'],$text),
                    'requirement_text'=>$text,'consequence_text'=>$r['status']??null,'severity'=>$this->severity($text),'source_id'=>$source,
                    'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
                ]); $stats['rules']++;
                if ($house=$this->houseFromText($text)) {
                    if ($this->route($matched['id'],$houses[strtolower($house)],$r['partner'],$source,'Inferred from creditor workbook wording: '.$text,30)) $stats['routes']++;
                }
            }

            foreach ($data['explicit_routes'] as $r) {
                if ($isIgnored($r['name'])) {
                    $this->sourceRow($r,['id'=>null,'method'=>'ignored_by_operator'],$r['house'],null);
                    $stats['rows']++; $stats['ignored']++; continue;
                }
                $matched=$match($r['name']);
                $this->sourceRow($r,$matched,$r['house'],null); $stats['rows']++; $matched?$stats['matched']++:$stats['unmatched']++;
                if (!$matched) continue;
                $text=$r['name'].' is listed under '.$r['house'].' in '.$r['sheet'];
                $source=$this->ruleSource($r,$text);
                if ($this->route($matched['id'],$houses[strtolower($r['house'])],$r['partner'],$source,$text,10)) $stats['routes']++;
            }

            foreach ($data['representative_catalog'] as $r) {
                if ($isIgnored($r['name'])) {
                    $this->sourceRow($r,['id'=>null,'method'=>'ignored_by_operator'],$r['house'],$r['data']??null);
                    $stats['rows']++; $stats['ignored']++; continue;
                }
                $matched=$match($r['name']);
                $this->sourceRow($r,$matched,$r['house'],$r['data']??null); $stats['rows']++; $matched?$stats['matched']++:$stats['unmatched']++;
                if (!$matched) continue;
                $detail = $r['data'] ?? [];
                $text = $r['name'].' is listed under '.$r['house'].' in '.$r['sheet'];
                if ($detail) $text .= ' | '.collect($detail)->filter(fn($v)=>$v!==null && $v!=='')->map(fn($v,$k)=>$k.': '.$v)->implode(' | ');
                $source=$this->ruleSource($r,$text);
                if ($this->route($matched['id'],$houses[strtolower($r['house'])],$r['partner'],$source,$text,15)) $stats['routes']++;
            }

            foreach ($data['representative_rules'] ?? [] as $r) {
                $text=trim((string)($r['text']??''));
                $houseKey=strtolower((string)($r['house']??''));
                if ($text==='' || !isset($houses[$houseKey])) continue;
                $source=$this->ruleSource($r,$text);
                DB::table('decision_rules')->insert([
                    'scope_type'=>'voting_house','voting_house_id'=>$houses[$houseKey],'partner_key'=>$r['partner']??null,
                    'category'=>$this->category($r['sheet'],$text),'requirement_text'=>$text,'severity'=>$this->severity($text),
                    'source_id'=>$source,'is_active'=>!$this->isHistorical($text),'created_at'=>now(),'updated_at'=>now(),
                ]);
                $stats['rules']++; $stats['representative_rules']++;
            }

            foreach ($data['general_rules'] ?? [] as $r) {
                $text=trim((string)($r['text']??''));
                if ($text==='') continue;
                $source=$this->ruleSource($r,$text);
                DB::table('decision_rules')->insert([
                    'scope_type'=>'partner','partner_key'=>$r['partner']??null,'category'=>$this->category($r['sheet'],$text),
                    'requirement_text'=>$text,'severity'=>$this->severity($text),'source_id'=>$source,
                    'is_active'=>!$this->isHistorical($text),'created_at'=>now(),'updated_at'=>now(),
                ]);
                $stats['rules']++; $stats['general_rules']++;
            }

            $seedSources=DB::table('decision_rule_sources')->where('source_type','workbook')->pluck('id');
            DB::table('decision_rules')->whereIn('source_id',$seedSources)->delete();
            DB::table('decision_rule_sources')->whereIn('id',$seedSources)->delete();
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
        if (str_contains($x,'do not vote')||str_contains($x,'non-voting')||str_contains($x,'abstain')) return 'non_voting';
        if (str_contains($x,'potentially')||str_contains($x,'speak with')||str_contains($x,'speak to')||str_contains($x,'case by case')||str_contains($x,'consider')||str_contains($x,'trial')||str_contains($x,'moc')) return 'conditional_or_escalation';
        if (str_contains($x,'reject')) return 'hard_or_conditional_reject';
        if (str_contains($x,'modify')||str_contains($x,'modification')||str_contains($x,'downgrade')) return 'modification';
        if (str_contains($x,'accept')) return 'acceptance';
        return 'requirement';
    }

    private function category(string $sheet,string $text): string {
        $x=strtolower($sheet.' '.$text);
        return match (true) {
            str_contains($x,'dividend') => 'dividend',
            str_contains($x,'council') => 'council',
            str_contains($x,'hmrc') || str_contains($x,'tax return') => 'hmrc',
            str_contains($x,'bounceback') || str_contains($x,'bounce back') => 'bounceback_loan',
            str_contains($x,'self employed') || str_contains($x,'self-employed') || str_contains($x,'cis ') => 'self_employed',
            str_contains($x,'property') || str_contains($x,'homeowner') || str_contains($x,'equity') || str_contains($x,'ltv') => 'property',
            str_contains($x,'car ') || str_contains($x,'vehicle') || str_contains($x,'hp/') || str_contains($x,'pcp') => 'vehicle',
            str_contains($x,'gambl') || str_contains($x,'gamstop') => 'gambling',
            str_contains($x,'bank statement') || str_contains($x,'wage slip') || str_contains($x,'proof') || str_contains($x,'evidence') => 'evidence',
            str_contains($x,'recent spend') || str_contains($x,'spending') || str_contains($x,'taken out') => 'recent_credit',
            str_contains($x,'income') || str_contains($x,'benefit') || str_contains($x,'dla') || str_contains($x,'pip') => 'income',
            str_contains($x,'sfs') || str_contains($x,'financial statement') || str_contains($x,'i&e') => 'ie',
            str_contains($x,'vote') || str_contains($x,'voting') || str_contains($x,'representative') => 'voting',
            default => 'general_criteria',
        };
    }

    private function isHistorical(string $text): bool {
        $x=strtolower($text);
        return str_contains($x,'close of business 30th june 2023') || str_contains($x,'historical only');
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
            'source_type'=>'workbook_decision_engine','name'=>$r['source'],'sheet'=>$r['sheet'],
            'location'=>'Row '.$r['row'],'original_text'=>$text,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    private function route(int $creditorId,int $houseId,?string $partner,int $source,string $condition,int $priority): bool {
        $exists=DB::table('creditor_voting_routes')->where('creditor_id',$creditorId)->where('voting_house_id',$houseId)
            ->where('partner_key',$partner)->where('is_active',true)->exists();
        if ($exists) return false;
        DB::table('creditor_voting_routes')->insert([
            'creditor_id'=>$creditorId,'voting_house_id'=>$houseId,'partner_key'=>$partner,'condition_text'=>$condition,
            'priority'=>$priority,'is_default'=>false,'is_active'=>true,'source_id'=>$source,'created_at'=>now(),'updated_at'=>now(),
        ]);
        return true;
    }
}
