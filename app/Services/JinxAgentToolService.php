<?php

namespace App\Services;

use App\Models\AssistantKnowledgeItem;
use App\Models\Debt;
use App\Models\DebtDocument;
use App\Models\Creditor;
use App\Models\Lead;
use App\Models\LeadChecklistItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class JinxAgentToolService
{
    public function __construct(private readonly VicidialCallbackService $callbacks, private readonly VicidialLeadImportService $leadImporter, private readonly JinxAgentIvaService $iva, private readonly LeadDebtService $debtService, private readonly LeadChecklistService $checklists) {}

    public function definitions(): array
    {
        return [
            ['type'=>'web_search_preview','search_context_size'=>'medium'],
            $this->fn('import_vicidial_lead_to_jinx','Find a VICIdial lead by phone number and create/link its Jinx case using the same core field mapping as the VICIdial webform. Use when asked to add/import a dialler phone number to Jinx. Returns the clickable Jinx case URL.',['phone_number'=>['type'=>'string']],['phone_number']),
            $this->fn('search_cases','Search Jinx CRM cases by client name, lead ID, status or source. Use this before assuming a case does not exist.',['query'=>['type'=>'string'],'status'=>['type'=>['string','null']]],['query','status']),
            $this->fn('get_case','Read a Jinx case including CRM fields, Financial Statement, debts, checklist and active callback.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('search_internal_knowledge','Search authoritative Jinx company/partner/IP knowledge, including Markdown rules and learned database rules. Use this for internal IVA packaging rules before relying on generic web information.',['query'=>['type'=>'string'],'scope'=>['type'=>['string','null']]],['query','scope']),
            $this->fn('save_internal_knowledge','Save or update a durable internal Jinx company, partner or IP rule. Use this when the user explicitly tells you to update, add, remember or change Jinx rules/knowledge. This is a real persistent knowledge write.',['scope'=>['type'=>'string','enum'=>['company','partner','ip']],'scope_key'=>['type'=>['string','null']],'category'=>['type'=>'string'],'title'=>['type'=>'string'],'content'=>['type'=>'string']],['scope','scope_key','category','title','content']),
            $this->fn('get_vicidial_state','Inspect the linked VICIdial lead, hopper membership and active callbacks for a Jinx case.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('schedule_callback','Book or replace a real VICIdial callback for a Jinx case. This writes VICIdial, sets CBHOLD, removes the lead from hopper and moves the Jinx case to WIP.',['lead_id'=>['type'=>'integer'],'callback_at'=>['type'=>'string'],'notes'=>['type'=>['string','null']]],['lead_id','callback_at','notes']),
            $this->fn('cancel_callback','Cancel any active/live VICIdial callback for a Jinx case. This is a real dialler write, keeps the lead out of the hopper, and changes CBHOLD/CALLBK to WIP.',['lead_id'=>['type'=>'integer'],'reason'=>['type'=>['string','null']]],['lead_id','reason']),
            $this->fn('update_wip_status','Change the Jinx WIP status for one case. This is a real CRM write.',['lead_id'=>['type'=>'integer'],'status'=>['type'=>'string']],['lead_id','status']),
            $this->fn('add_case_note','Append a timestamped assistant note to the Jinx case notes. This is a real CRM write and preserves existing notes.',['lead_id'=>['type'=>'integer'],'note'=>['type'=>'string']],['lead_id','note']),
            $this->fn('update_case_field','Update one ordinary Jinx CRM case field. Use for client/contact/address/employment/debt estimate/source changes.',['lead_id'=>['type'=>'integer'],'field'=>['type'=>'string'],'value'=>['type'=>['string','number','null']]],['lead_id','field','value']),
            $this->fn('update_ie_fact','Write one deterministic I&E input fact to the Jinx financial statement. For children ages use a comma-separated value such as 11,8,3.',['lead_id'=>['type'=>'integer'],'key'=>['type'=>'string'],'value'=>['type'=>['string','number','boolean']]],['lead_id','key','value']),
            $this->fn('calculate_ie','Run the existing deterministic Jinx I&E calculator for a case and persist the resulting financial statement, DI, SFS analysis and calculated expenditure.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('review_iva_case','Build a read-only IVA packaging review context: current deterministic I&E preview, debt total, voting-house exposure, partner profile and outstanding checklist. Follow with internal-rule search for suitability questions.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('calculate_target_di','Calculate target monthly DI using the company base-fee formula.',['total_debt'=>['type'=>'number'],'dividend_percent'=>['type'=>'number'],'months'=>['type'=>'integer']],['total_debt','dividend_percent','months']),
            $this->fn('search_creditors','Search Jinx creditor records before adding or changing a debt.',['query'=>['type'=>'string']],['query']),
            $this->fn('add_debt','Add a real debt to a Jinx case using an existing creditor ID. Search creditors first.',['lead_id'=>['type'=>'integer'],'creditor_id'=>['type'=>'integer'],'balance'=>['type'=>'number'],'source_expected'=>['type'=>'string'],'reference'=>['type'=>['string','null']]],['lead_id','creditor_id','balance','source_expected','reference']),
            $this->fn('update_debt','Update an existing Jinx debt balance, creditor, evidence source or reference.',['debt_id'=>['type'=>'integer'],'creditor_id'=>['type'=>'integer'],'balance'=>['type'=>'number'],'source_expected'=>['type'=>'string'],'reference'=>['type'=>['string','null']]],['debt_id','creditor_id','balance','source_expected','reference']),
            $this->fn('delete_debt','Delete one Jinx debt by debt ID and resync the case checklist.',['debt_id'=>['type'=>'integer']],['debt_id']),
            $this->fn('set_checklist_item','Mark a Jinx packaging checklist item complete or incomplete.',['lead_id'=>['type'=>'integer'],'item_id'=>['type'=>'integer'],'complete'=>['type'=>'boolean']],['lead_id','item_id','complete']),

            $this->fn('search_jinx_code','Search the Jinx Laravel source code for implementation details. Read-only. Use when the user asks how Jinx works or when diagnosing application behaviour.',['query'=>['type'=>'string']],['query']),
            $this->fn('read_jinx_file','Read a Jinx Laravel source/config/migration file by repository-relative path. Read-only and restricted to application code.',['path'=>['type'=>'string']],['path']),
            $this->fn('search_laravel_log','Search recent Laravel log lines for an error, lead ID or keyword. Read-only.',['query'=>['type'=>'string']],['query']),
        ];
    }

    public function execute(string $name,array $args): array
    {
        return match($name) {
            'import_vicidial_lead_to_jinx'=>$this->importVicidialLead($args), 'search_cases'=>$this->searchCases($args), 'get_case'=>$this->getCase($args),
            'search_internal_knowledge'=>$this->searchKnowledge($args), 'save_internal_knowledge'=>$this->saveKnowledge($args), 'get_vicidial_state'=>$this->vicidialState($args),
            'schedule_callback'=>$this->scheduleCallback($args), 'cancel_callback'=>$this->cancelCallback($args), 'update_wip_status'=>$this->updateStatus($args),
            'add_case_note'=>$this->addNote($args), 'update_case_field'=>$this->updateCaseField($args), 'update_ie_fact'=>$this->updateIeFact($args), 'calculate_ie'=>$this->calculateIe($args), 'review_iva_case'=>$this->reviewIvaCase($args), 'calculate_target_di'=>$this->calculateTargetDi($args), 'search_creditors'=>$this->searchCreditors($args), 'add_debt'=>$this->addDebt($args), 'update_debt'=>$this->updateDebt($args), 'delete_debt'=>$this->deleteDebt($args), 'set_checklist_item'=>$this->setChecklistItem($args), 'search_jinx_code'=>$this->searchCode($args), 'read_jinx_file'=>$this->readCodeFile($args), 'search_laravel_log'=>$this->searchLog($args), default=>throw new RuntimeException('Unknown Jinx agent tool: '.$name),
        };
    }

    private function fn(string $name,string $description,array $properties,array $required): array
    { return ['type'=>'function','name'=>$name,'description'=>$description,'strict'=>true,'parameters'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false]]; }

    private function importVicidialLead(array $a): array
    { return ['success'=>true,'result'=>$this->leadImporter->importByPhone((string)($a['phone_number']??''))]; }

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
        $check=LeadChecklistItem::query()->where('lead_id',$l->id)->get()->map(fn($i)=>['item_id'=>$i->id,'item'=>$i->item_name??('Item '.$i->id),'complete'=>(bool)$i->is_complete,'source_type'=>$i->source_type])->all();
        $cb=collect($this->callbacks->activeForJinxLeads())->firstWhere('lead_id',$l->id);
        return ['case'=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'status'=>$l->wip_status,'source'=>$l->source,'vicidial_lead_id'=>$l->vicidial_lead_id,'employment_status'=>$l->employment_status,'monthly_income'=>$l->monthly_income,'estimated_total_debt'=>$l->estimated_total_debt,'case_notes'=>$l->case_notes,'financial_statement'=>$l->financial_statement,'debts'=>$l->debts->map(fn($d)=>['debt_id'=>$d->id,'creditor_id'=>$d->creditor_id,'creditor'=>$d->creditor?->name,'balance'=>(float)$d->balance,'source_expected'=>$d->source_expected,'reference'=>$d->reference])->all(),'checklist'=>$check,'active_callback'=>$cb]];
    }

    private function searchKnowledge(array $a): array
    {
        $q=Str::lower(trim((string)$a['query'])); $scope=Str::lower(trim((string)($a['scope']??''))); $terms=collect(preg_split('/[^a-z0-9]+/',$q)?:[])->filter(fn($x)=>strlen($x)>2)->unique()->take(8);
        $db=AssistantKnowledgeItem::query()->active()->when($scope!=='',fn($x)=>$x->where(function($y)use($scope){$y->whereRaw('LOWER(scope_key)=? ',[$scope])->orWhereRaw('LOWER(scope)=?',[$scope]);}))->get()->filter(function($i)use($terms){$hay=Str::lower($i->title.' '.$i->content);return $terms->contains(fn($t)=>Str::contains($hay,$t));})->take(12)->map(fn($i)=>['source'=>'learned:'.$i->scope.($i->scope_key?'/'.$i->scope_key:''),'title'=>$i->title,'content'=>Str::limit($i->content,1800)])->values()->all();
        $files=[]; foreach(glob(resource_path('assistant/knowledge').'/*/*.md')?:[] as $path){$text=(string)@file_get_contents($path);$hay=Str::lower($text.' '.basename($path));$score=$terms->filter(fn($t)=>Str::contains($hay,$t))->count();if($score>0)$files[]=['score'=>$score,'source'=>Str::after($path,resource_path('assistant/knowledge').'/'),'content'=>Str::limit($text,3500)];}
        usort($files,fn($x,$y)=>$y['score']<=>$x['score']); return ['authoritative_internal_results'=>array_merge($db,array_slice($files,0,8))];
    }

    private function saveKnowledge(array $a): array
    {
        $scope=(string)$a['scope'];$key=trim((string)($a['scope_key']??''));$title=trim((string)$a['title']);$content=trim((string)$a['content']);$category=trim((string)$a['category']);
        if(!in_array($scope,['company','partner','ip'],true))throw new RuntimeException('Invalid knowledge scope.');
        if($scope!=='company'&&$key==='')throw new RuntimeException('Partner/IP knowledge requires a scope key.');
        if($title===''||$content==='')throw new RuntimeException('Knowledge title and content are required.');
        $existing=AssistantKnowledgeItem::query()->active()->where('scope',$scope)->where(function($q)use($key){$key===''?$q->whereNull('scope_key'):$q->whereRaw('LOWER(scope_key)=?',[Str::lower($key)]);})->whereRaw('LOWER(title)=?',[Str::lower($title)])->latest('id')->first();
        if($existing){$existing->update(['category'=>$category?:'General','content'=>$content,'metadata'=>array_merge($existing->metadata??[],['source'=>'jinx_agent_chat','updated_at'=>now()->toIso8601String()])]);$item=$existing;$action='updated';}
        else{$item=AssistantKnowledgeItem::create(['scope'=>$scope,'scope_key'=>$key!==''?$key:null,'category'=>$category?:'General','title'=>$title,'content'=>$content,'status'=>'active','metadata'=>['source'=>'jinx_agent_chat']]);$action='created';}
        return ['success'=>true,'action'=>$action,'knowledge_id'=>$item->id,'scope'=>$scope,'scope_key'=>$item->scope_key,'title'=>$item->title,'content'=>$item->content];
    }

    private function vicidialState(array $a): array
    {
        $l=Lead::findOrFail((int)$a['lead_id']); if(!$l->vicidial_lead_id)throw new RuntimeException('Case is not linked to VICIdial.');$id=(int)$l->vicidial_lead_id;$c=(string)config('services.vicidial.db_connection','asterisk');
        $v=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first();$hopper=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->get(['hopper_id','status','priority']);$callbacks=DB::connection($c)->table('vicidial_callbacks')->where('lead_id',$id)->whereIn('status',['ACTIVE','LIVE'])->orderBy('callback_time')->get(['callback_id','status','callback_time','comments','lead_status']);
        return ['jinx'=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'wip_status'=>$l->wip_status],'vicidial_lead'=>$v?(array)$v:null,'hopper'=>$hopper->map(fn($x)=>(array)$x)->all(),'active_callbacks'=>$callbacks->map(fn($x)=>(array)$x)->all()];
    }

    private function scheduleCallback(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$when=Carbon::parse((string)$a['callback_at']);if($when->isPast())throw new RuntimeException('Callback time is in the past.');return ['success'=>true,'result'=>$this->callbacks->schedule($l,$when,(string)($a['notes']??''))]; }
    private function cancelCallback(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);return ['success'=>true,'result'=>$this->callbacks->cancel($l,(string)($a['reason']??''))]; }
    private function updateStatus(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$status=(string)$a['status'];if(!in_array($status,Lead::WIP_STATUSES,true))throw new RuntimeException('Invalid WIP status.');$old=$l->wip_status;$l->update(['wip_status'=>$status]);return ['success'=>true,'lead_id'=>$l->id,'old_status'=>$old,'new_status'=>$status]; }
    private function addNote(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$note=trim((string)$a['note']);if($note==='')throw new RuntimeException('Note is empty.');$line='['.now()->format('d/m/Y H:i').'] Jinx Assistant: '.$note;$l->update(['case_notes'=>trim((string)$l->case_notes).(filled($l->case_notes)?"\n\n":'').$line]);return ['success'=>true,'lead_id'=>$l->id,'note'=>$line]; }
    private function updateCaseField(array $a): array
    {
        $l=Lead::findOrFail((int)$a['lead_id']);$field=(string)$a['field'];$allowed=['title','first_name','middle_name','last_name','dob','email','phone_number','house_number','house_name','building_number','postcode','address_line_1','employment_status','estimated_total_debt','source'];
        if(!in_array($field,$allowed,true))throw new RuntimeException('Unsupported case field.');$old=$l->{$field};$l->update([$field=>$a['value']]);return ['success'=>true,'lead_id'=>$l->id,'field'=>$field,'old_value'=>$old,'new_value'=>$l->fresh()->{$field}];
    }
    private function updateIeFact(array $a): array {return ['success'=>true,'result'=>$this->iva->updateFact(Lead::findOrFail((int)$a['lead_id']),(string)$a['key'],$a['value'])];}
    private function calculateIe(array $a): array {return ['success'=>true,'result'=>$this->iva->calculate(Lead::findOrFail((int)$a['lead_id']))];}
    private function reviewIvaCase(array $a): array {return $this->iva->review(Lead::findOrFail((int)$a['lead_id']));}
    private function calculateTargetDi(array $a): array {return $this->iva->targetDi((float)$a['total_debt'],(float)$a['dividend_percent'],(int)$a['months']);}
    private function searchCreditors(array $a): array
    { $q=trim((string)$a['query']);return ['creditors'=>Creditor::query()->where('name','like','%'.$q.'%')->orWhereHas('aliases',fn($x)=>$x->where('alias','like','%'.$q.'%'))->limit(20)->get()->map(fn($c)=>['creditor_id'=>$c->id,'name'=>$c->name,'voting_house'=>$c->voting_house,'voting_practices'=>array_values(array_filter([$c->voting_practice1,$c->voting_practice2,$c->voting_practice3]))])->all()]; }
    private function addDebt(array $a): array
    { $this->validateDebtArgs($a);$l=Lead::findOrFail((int)$a['lead_id']);$d=$this->debtService->createForLead($l,['creditor_id'=>(int)$a['creditor_id'],'balance'=>(float)$a['balance'],'source_expected'=>(string)$a['source_expected'],'reference'=>$a['reference']]);$l->update(['estimated_total_debt'=>Debt::where('lead_id',$l->id)->sum('balance')]);return ['success'=>true,'debt_id'=>$d->id,'lead_id'=>$l->id,'estimated_total_debt'=>(float)$l->fresh()->estimated_total_debt]; }
    private function updateDebt(array $a): array
    { $this->validateDebtArgs($a);$d=Debt::findOrFail((int)$a['debt_id']);$d->update(['creditor_id'=>(int)$a['creditor_id'],'balance'=>(float)$a['balance'],'source_expected'=>(string)$a['source_expected'],'reference'=>$a['reference']]);$doc=$d->document;if($doc)$doc->update(['proof_type'=>(string)$a['source_expected'],'is_complete'=>(string)$a['source_expected']==='credit_check']);$this->checklists->syncForLead($d->lead);$d->lead->update(['estimated_total_debt'=>Debt::where('lead_id',$d->lead_id)->sum('balance')]);return ['success'=>true,'debt_id'=>$d->id,'lead_id'=>$d->lead_id]; }
    private function deleteDebt(array $a): array
    { $d=Debt::findOrFail((int)$a['debt_id']);$l=$d->lead;$id=$d->id;$docId=$d->document?->id;if($docId)LeadChecklistItem::where('lead_id',$l->id)->where('source_type','debt_document')->where('source_id',$docId)->delete();$d->delete();$this->checklists->syncForLead($l);$l->update(['estimated_total_debt'=>Debt::where('lead_id',$l->id)->sum('balance')]);return ['success'=>true,'deleted_debt_id'=>$id,'lead_id'=>$l->id,'estimated_total_debt'=>(float)$l->fresh()->estimated_total_debt]; }
    private function setChecklistItem(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$i=LeadChecklistItem::where('lead_id',$l->id)->findOrFail((int)$a['item_id']);$i->update(['is_complete'=>(bool)$a['complete']]);$this->checklists->syncLeadStatus($l);return ['success'=>true,'item_id'=>$i->id,'item'=>$i->item_name,'complete'=>(bool)$i->fresh()->is_complete,'wip_status'=>$l->fresh()->wip_status]; }
    private function validateDebtArgs(array $a): void
    { if(!Creditor::whereKey((int)$a['creditor_id'])->exists())throw new RuntimeException('Creditor not found.');if((float)$a['balance']<0)throw new RuntimeException('Debt balance cannot be negative.');if(!in_array((string)$a['source_expected'],['credit_check','3wc','screenshot_pdf','live_chat','other'],true))throw new RuntimeException('Invalid debt evidence source.'); }

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
