<?php

namespace App\Services;

use App\Models\AssistantKnowledgeItem;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\LeadChecklistItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class JinxAgentToolService
{
    public function __construct(private readonly VicidialCallbackService $callbacks) {}

    public function definitions(): array
    {
        return [
            ['type'=>'web_search_preview','search_context_size'=>'medium'],
            $this->fn('search_cases','Search Jinx CRM cases by client name, lead ID, status or source. Use this before assuming a case does not exist.',['query'=>['type'=>'string'],'status'=>['type'=>['string','null']]],['query','status']),
            $this->fn('get_case','Read a Jinx case including CRM fields, Financial Statement, debts, checklist and active callback.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('search_internal_knowledge','Search authoritative Jinx company/partner/IP knowledge, including Markdown rules and learned database rules. Use this for internal IVA packaging rules before relying on generic web information.',['query'=>['type'=>'string'],'scope'=>['type'=>['string','null']]],['query','scope']),
            $this->fn('get_vicidial_state','Inspect the linked VICIdial lead, hopper membership and active callbacks for a Jinx case.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('schedule_callback','Book or replace a real VICIdial callback for a Jinx case. This writes VICIdial, sets CBHOLD, removes the lead from hopper and moves the Jinx case to WIP.',['lead_id'=>['type'=>'integer'],'callback_at'=>['type'=>'string'],'notes'=>['type'=>['string','null']]],['lead_id','callback_at','notes']),
            $this->fn('update_wip_status','Change the Jinx WIP status for one case. This is a real CRM write.',['lead_id'=>['type'=>'integer'],'status'=>['type'=>'string']],['lead_id','status']),
            $this->fn('add_case_note','Append a timestamped assistant note to the Jinx case notes. This is a real CRM write and preserves existing notes.',['lead_id'=>['type'=>'integer'],'note'=>['type'=>'string']],['lead_id','note']),
            $this->fn('search_jinx_code','Search the Jinx Laravel source code for implementation details. Read-only. Use when the user asks how Jinx works or when diagnosing application behaviour.',['query'=>['type'=>'string']],['query']),
            $this->fn('read_jinx_file','Read a Jinx Laravel source/config/migration file by repository-relative path. Read-only and restricted to application code.',['path'=>['type'=>'string']],['path']),
            $this->fn('search_laravel_log','Search recent Laravel log lines for an error, lead ID or keyword. Read-only.',['query'=>['type'=>'string']],['query']),
        ];
    }

    public function execute(string $name,array $args): array
    {
        return match($name) {
            'search_cases'=>$this->searchCases($args), 'get_case'=>$this->getCase($args),
            'search_internal_knowledge'=>$this->searchKnowledge($args), 'get_vicidial_state'=>$this->vicidialState($args),
            'schedule_callback'=>$this->scheduleCallback($args), 'update_wip_status'=>$this->updateStatus($args),
            'add_case_note'=>$this->addNote($args), 'search_jinx_code'=>$this->searchCode($args), 'read_jinx_file'=>$this->readCodeFile($args), 'search_laravel_log'=>$this->searchLog($args), default=>throw new RuntimeException('Unknown Jinx agent tool: '.$name),
        };
    }

    private function fn(string $name,string $description,array $properties,array $required): array
    { return ['type'=>'function','name'=>$name,'description'=>$description,'strict'=>true,'parameters'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false]]; }

    private function searchCases(array $a): array
    {
        $q=trim((string)($a['query']??'')); $status=trim((string)($a['status']??''));
        $query=Lead::query(); if($status!=='')$query->where('wip_status',$status);
        if($q!=='')$query->where(function($x)use($q){$x->where('first_name','like','%'.$q.'%')->orWhere('last_name','like','%'.$q.'%')->orWhereRaw("CONCAT(first_name,' ',last_name) like ?",['%'.$q.'%'])->orWhere('source','like','%'.$q.'%');if(ctype_digit($q))$x->orWhere('id',(int)$q)->orWhere('vicidial_lead_id',(int)$q);});
        return ['cases'=>$query->latest('updated_at')->limit(25)->get()->map(fn(Lead $l)=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'status'=>$l->wip_status,'source'=>$l->source,'vicidial_lead_id'=>$l->vicidial_lead_id])->all()];
    }

    private function getCase(array $a): array
    {
        $l=Lead::with('debts.creditor')->findOrFail((int)$a['lead_id']);
        $check=LeadChecklistItem::query()->where('lead_id',$l->id)->get()->map(fn($i)=>['item'=>$i->item_name??('Item '.$i->id),'complete'=>(bool)$i->is_complete])->all();
        $cb=collect($this->callbacks->activeForJinxLeads())->firstWhere('lead_id',$l->id);
        return ['case'=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'status'=>$l->wip_status,'source'=>$l->source,'vicidial_lead_id'=>$l->vicidial_lead_id,'employment_status'=>$l->employment_status,'monthly_income'=>$l->monthly_income,'estimated_total_debt'=>$l->estimated_total_debt,'case_notes'=>$l->case_notes,'financial_statement'=>$l->financial_statement,'debts'=>$l->debts->map(fn($d)=>['creditor'=>$d->creditor?->name,'balance'=>$d->balance])->all(),'checklist'=>$check,'active_callback'=>$cb]];
    }

    private function searchKnowledge(array $a): array
    {
        $q=Str::lower(trim((string)$a['query'])); $scope=Str::lower(trim((string)($a['scope']??''))); $terms=collect(preg_split('/[^a-z0-9]+/',$q)?:[])->filter(fn($x)=>strlen($x)>2)->unique()->take(8);
        $db=AssistantKnowledgeItem::query()->active()->when($scope!=='',fn($x)=>$x->where(function($y)use($scope){$y->whereRaw('LOWER(scope_key)=? ',[$scope])->orWhereRaw('LOWER(scope)=?',[$scope]);}))->get()->filter(function($i)use($terms){$hay=Str::lower($i->title.' '.$i->content);return $terms->contains(fn($t)=>Str::contains($hay,$t));})->take(12)->map(fn($i)=>['source'=>'learned:'.$i->scope.($i->scope_key?'/'.$i->scope_key:''),'title'=>$i->title,'content'=>Str::limit($i->content,1800)])->values()->all();
        $files=[]; foreach(glob(resource_path('assistant/knowledge').'/*/*.md')?:[] as $path){$text=(string)@file_get_contents($path);$hay=Str::lower($text.' '.basename($path));$score=$terms->filter(fn($t)=>Str::contains($hay,$t))->count();if($score>0)$files[]=['score'=>$score,'source'=>Str::after($path,resource_path('assistant/knowledge').'/'),'content'=>Str::limit($text,3500)];}
        usort($files,fn($x,$y)=>$y['score']<=>$x['score']); return ['authoritative_internal_results'=>array_merge($db,array_slice($files,0,8))];
    }

    private function vicidialState(array $a): array
    {
        $l=Lead::findOrFail((int)$a['lead_id']); if(!$l->vicidial_lead_id)throw new RuntimeException('Case is not linked to VICIdial.');$id=(int)$l->vicidial_lead_id;$c=(string)config('services.vicidial.db_connection','asterisk');
        $v=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first();$hopper=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->get(['hopper_id','status','priority']);$callbacks=DB::connection($c)->table('vicidial_callbacks')->where('lead_id',$id)->whereIn('status',['ACTIVE','LIVE'])->orderBy('callback_time')->get(['callback_id','status','callback_time','comments','lead_status']);
        return ['jinx'=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'wip_status'=>$l->wip_status],'vicidial_lead'=>$v?(array)$v:null,'hopper'=>$hopper->map(fn($x)=>(array)$x)->all(),'active_callbacks'=>$callbacks->map(fn($x)=>(array)$x)->all()];
    }

    private function scheduleCallback(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$when=Carbon::parse((string)$a['callback_at']);if($when->isPast())throw new RuntimeException('Callback time is in the past.');return ['success'=>true,'result'=>$this->callbacks->schedule($l,$when,(string)($a['notes']??''))]; }
    private function updateStatus(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$status=(string)$a['status'];if(!in_array($status,Lead::WIP_STATUSES,true))throw new RuntimeException('Invalid WIP status.');$old=$l->wip_status;$l->update(['wip_status'=>$status]);return ['success'=>true,'lead_id'=>$l->id,'old_status'=>$old,'new_status'=>$status]; }
    private function addNote(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$note=trim((string)$a['note']);if($note==='')throw new RuntimeException('Note is empty.');$line='['.now()->format('d/m/Y H:i').'] Jinx Assistant: '.$note;$l->update(['case_notes'=>trim((string)$l->case_notes).(filled($l->case_notes)?"\n\n":'').$line]);return ['success'=>true,'lead_id'=>$l->id,'note'=>$line]; }
    private function searchCode(array $a): array
    {
        $q=trim((string)$a['query']);if(strlen($q)<2)throw new RuntimeException('Search query too short.');$roots=['app','routes','resources/views','database/migrations','config'];$hits=[];
        foreach($roots as $root){$base=base_path($root);if(!is_dir($base))continue;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS));foreach($it as $f){if(!$f->isFile()||$f->getSize()>800000)continue;$ext=strtolower($f->getExtension());if(!in_array($ext,['php','blade.php','md'],true)&&!str_ends_with($f->getFilename(),'.blade.php'))continue;$lines=@file($f->getPathname(),FILE_IGNORE_NEW_LINES);if(!$lines)continue;foreach($lines as $n=>$line)if(stripos($line,$q)!==false){$hits[]=['path'=>Str::after($f->getPathname(),base_path().DIRECTORY_SEPARATOR),'line'=>$n+1,'excerpt'=>Str::limit(trim($line),500)];if(count($hits)>=40)break 3;}}}
        return ['query'=>$q,'matches'=>$hits];
    }

    private function readCodeFile(array $a): array
    {
        $rel=ltrim(str_replace('\\','/',trim((string)$a['path'])),'/');if(str_contains($rel,'..'))throw new RuntimeException('Invalid path.');$allowed=['app/','routes/','resources/views/','resources/assistant/knowledge/','database/migrations/','config/'];if(!collect($allowed)->contains(fn($x)=>str_starts_with($rel,$x)))throw new RuntimeException('Path is outside the readable Jinx code areas.');$path=base_path($rel);if(!is_file($path))throw new RuntimeException('File not found.');$text=(string)file_get_contents($path);return ['path'=>$rel,'content'=>Str::limit($text,16000)];
    }

    private function searchLog(array $a): array
    {
        $q=trim((string)$a['query']);$path=storage_path('logs/laravel.log');if(!is_file($path))return ['matches'=>[]];$size=filesize($path);$fh=fopen($path,'rb');if($size>1500000)fseek($fh,-1500000,SEEK_END);$text=stream_get_contents($fh);fclose($fh);$lines=preg_split('/\R/',$text)?:[];$matches=[];foreach($lines as $line)if(stripos($line,$q)!==false)$matches[]=Str::limit($line,1200);return ['query'=>$q,'matches'=>array_slice($matches,-40)];
    }

}
