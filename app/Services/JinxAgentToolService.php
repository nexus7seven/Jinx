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
    public function __construct(
        private readonly VicidialCallbackService $callbacks,
        private readonly VicidialLeadImportService $leadImporter,
        private readonly JinxAgentIvaService $iva,
        private readonly LeadDebtService $debtService,
        private readonly LeadChecklistService $checklists,
        private readonly DecisionCaseFactService $decisionFacts,
        private readonly IvaDecisionEngineService $decisionEngine,
        private readonly PropertyDecisionService $propertyDecision,
        private readonly RefreshDmpDecisionService $dmpDecision,
    ) {}

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
            $this->fn('search_vicidial_leads','Search VICIdial directly by phone number, lead ID, client name, vendor/source or list ID.',['query'=>['type'=>'string']],['query']),
            $this->fn('get_vicidial_history','Inspect a VICIdial lead with outbound/inbound call history, dispositions, agents, callbacks, hopper state and list/campaign context.',['vicidial_lead_id'=>['type'=>'integer'],'limit'=>['type'=>'integer']],['vicidial_lead_id','limit']),
            $this->fn('inspect_vicidial_campaign','Read VICIdial campaign/list configuration relevant to dialling and hopper diagnostics.',['campaign_id'=>['type'=>['string','null']],'list_id'=>['type'=>['integer','null']]],['campaign_id','list_id']),
            $this->fn('audit_vicidial_hopper','Audit the current VICIdial hopper and cross-check queued leads against Jinx workflow stages and campaign dialable statuses. Read-only.',['campaign_id'=>['type'=>['string','null']]],['campaign_id']),
            $this->fn('summarize_vicidial_activity','Return structured dialler activity totals and dispositions for a period, optionally filtered by campaign, list or vendor/source. Read-only.',['hours'=>['type'=>'integer'],'campaign_id'=>['type'=>['string','null']],'list_id'=>['type'=>['integer','null']],'vendor_contains'=>['type'=>['string','null']]],['hours','campaign_id','list_id','vendor_contains']),
            $this->fn('diagnose_vicidial_performance','Diagnose dialler patterns over a period by comparing current activity with the immediately preceding equal period. Returns factual changes, concentration and operational flags; use before explaining why performance changed. Read-only.',['hours'=>['type'=>'integer'],'campaign_id'=>['type'=>['string','null']],'list_id'=>['type'=>['integer','null']],'vendor_contains'=>['type'=>['string','null']]],['hours','campaign_id','list_id','vendor_contains']),
            $this->fn('trace_vicidial_lead_dial_path','Trace why one VICIdial lead is or was eligible to enter the hopper and be dialled. Correlates Jinx stage, lead/list/campaign status, hopper source, callbacks and call history, and explains the VICIdial selection mechanism without changing anything.',['vicidial_lead_id'=>['type'=>'integer']],['vicidial_lead_id']),
            $this->fn('get_dialler_change_audit','Read recent Jinx Agent VICIdial change audit records, optionally for one Jinx case or VICIdial lead.',['lead_id'=>['type'=>['integer','null']],'vicidial_lead_id'=>['type'=>['integer','null']],'limit'=>['type'=>'integer']],['lead_id','vicidial_lead_id','limit']),
            $this->fn('update_vicidial_lead_status','Change one VICIdial lead status. Real dialler write; WIP/HOLD/CBHOLD/CALLBK are removed from hopper.',['vicidial_lead_id'=>['type'=>'integer'],'status'=>['type'=>'string']],['vicidial_lead_id','status']),
            $this->fn('remove_vicidial_from_hopper','Remove one VICIdial lead from the hopper without changing its status.',['vicidial_lead_id'=>['type'=>'integer']],['vicidial_lead_id']),
            $this->fn('update_vicidial_comments','Update one VICIdial lead comments field.',['vicidial_lead_id'=>['type'=>'integer'],'comments'=>['type'=>'string']],['vicidial_lead_id','comments']),
            $this->fn('check_crm_dialler_consistency','Diagnose one Jinx case against its linked VICIdial lead. Reports CRM/dialler status mismatches, inappropriate hopper membership, callback/status inconsistencies and broken/missing links without changing anything.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('repair_crm_dialler_consistency','Repair safe case-level CRM/VICIdial inconsistencies. Removes inappropriate hopper rows and aligns VICIdial operational status with a clear Jinx WIP/callback state. Use only when the user clearly asks to fix/reconcile the case.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('schedule_callback','Book or replace a real VICIdial callback for a Jinx case. This writes VICIdial, sets CBHOLD, removes the lead from hopper and moves the Jinx case to WIP.',['lead_id'=>['type'=>'integer'],'callback_at'=>['type'=>'string'],'notes'=>['type'=>['string','null']]],['lead_id','callback_at','notes']),
            $this->fn('cancel_callback','Cancel any active/live VICIdial callback for a Jinx case. This is a real dialler write, keeps the lead out of the hopper, and changes CBHOLD/CALLBK to WIP.',['lead_id'=>['type'=>'integer'],'reason'=>['type'=>['string','null']]],['lead_id','reason']),
            $this->fn('update_wip_status','Change the Jinx workflow stage for one case. This is a real CRM write. Dead requires a reason.',['lead_id'=>['type'=>'integer'],'status'=>['type'=>'string'],'reason'=>['type'=>['string','null']]],['lead_id','status','reason']),
            $this->fn('add_case_note','Append a timestamped assistant note to the Jinx case notes. This is a real CRM write and preserves existing notes.',['lead_id'=>['type'=>'integer'],'note'=>['type'=>'string']],['lead_id','note']),
            $this->fn('update_case_field','Update one ordinary Jinx CRM case field. Use for client/contact/address/employment/debt estimate/source changes.',['lead_id'=>['type'=>'integer'],'field'=>['type'=>'string'],'value'=>['type'=>['string','number','null']]],['lead_id','field','value']),
            $this->fn('update_ie_fact','Write one deterministic I&E input fact to the Jinx financial statement. For children ages use a comma-separated value such as 11,8,3.',['lead_id'=>['type'=>'integer'],'key'=>['type'=>'string'],'value'=>['type'=>['string','number','boolean']]],['lead_id','key','value']),
            $this->fn('calculate_ie','Run the existing deterministic Jinx I&E calculator for a case and persist the resulting financial statement, DI, SFS analysis and calculated expenditure.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('review_iva_case','Build a read-only IVA packaging review context: current deterministic I&E preview, debt total, voting-house exposure, partner profile and outstanding checklist. Follow with internal-rule search for suitability questions.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('assess_iva_case_decision','Build the whole-case decision-engine overview using the fixed business route order Zebra, Lawson Fox, AC, Assure, TIG, plus property analysis, recorded decision facts, I&E adjustments and Refresh DMP fallback. This is the starting point for route decisions.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('analyse_iva_route','Analyse one proposed IVA route using the case debts, voting-house exposure, sourced decision rules and relevant learned corrections/precedents. Use this when deciding whether a case fits Zebra, Lawson Fox, AC, Assure or TIG.',['lead_id'=>['type'=>'integer'],'destination'=>['type'=>'string']],['lead_id','destination']),
            $this->fn('get_decision_case_facts','Read structured decision-engine facts for a case and its debts, including property/evidence/DMP/voting facts and the I&E adjustment ledger.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('set_decision_case_fact','Store one structured decision-engine case fact. Use for facts such as homeownership, property value/mortgage/share, immigration/licence evidence, partner evidence availability, jurisdiction or DMP context.',['lead_id'=>['type'=>'integer'],'key'=>['type'=>'string'],'value'=>['type'=>['string','number','boolean','null']],'source_detail'=>['type'=>['string','null']]],['lead_id','key','value','source_detail']),
            $this->fn('set_debt_decision_fact','Store one structured decision-engine fact for a debt line, such as product type, contractual payment, payments made, current-provider status, recent-spend/account dates, attachments or voting override.',['debt_id'=>['type'=>'integer'],'key'=>['type'=>'string'],'value'=>['type'=>['string','number','boolean','null']],'source_detail'=>['type'=>['string','null']]],['debt_id','key','value','source_detail']),
            $this->fn('record_ie_adjustment','Record an I&E packaging adjustment in the auditable ledger: original amount, proposed amount, reason, optional supporting rule/evidence and status. This does not silently change the I&E itself.',['lead_id'=>['type'=>'integer'],'section_key'=>['type'=>'string'],'original_amount'=>['type'=>'number'],'proposed_amount'=>['type'=>'number'],'reason'=>['type'=>'string'],'rule_id'=>['type'=>['integer','null']],'evidence'=>['type'=>['string','null']],'status'=>['type'=>'string']],['lead_id','section_key','original_amount','proposed_amount','reason','rule_id','evidence','status']),
            $this->fn('set_ie_adjustment_status','Change a recorded I&E adjustment to proposed, accepted, rejected or superseded without deleting its audit history.',['lead_id'=>['type'=>'integer'],'adjustment_id'=>['type'=>'integer'],'status'=>['type'=>'string']],['lead_id','adjustment_id','status']),
            $this->fn('assess_property_case','Calculate attributable property equity from recorded facts and return the supplied property rules for one IVA destination.',['lead_id'=>['type'=>'integer'],'destination'=>['type'=>['string','null']]],['lead_id','destination']),
            $this->fn('assess_refresh_dmp','Assess Refresh DMP fallback criteria using the supplied September 2026 pack and recorded case/debt facts.',['lead_id'=>['type'=>'integer']],['lead_id']),
            $this->fn('audit_decision_engine_knowledge','Audit decision-engine source coverage, unresolved creditor mappings and conflicting voting-representative mappings. Optionally audit one case/route. Read-only.',['lead_id'=>['type'=>['integer','null']],'destination'=>['type'=>['string','null']]],['lead_id','destination']),
            $this->fn('record_voting_snapshot','Persist the current route-specific debt voting landscape so later rule changes do not rewrite the historical assessment.',['lead_id'=>['type'=>'integer'],'destination'=>['type'=>'string']],['lead_id','destination']),
            $this->fn('record_decision_assessment','Persist a final whole-case decision record, including a fresh decision-engine assessment and, for an IVA route, a voting snapshot and full selected-route rule context.',['lead_id'=>['type'=>'integer'],'preferred_route'=>['type'=>'string'],'status'=>['type'=>'string'],'rationale'=>['type'=>'string'],'actions'=>['type'=>['string','null']]],['lead_id','preferred_route','status','rationale','actions']),
            $this->fn('get_decision_assessments','Read recent persisted whole-case decision assessments for a case, including the historical rule/voting context saved at the time.',['lead_id'=>['type'=>'integer'],'limit'=>['type'=>'integer']],['lead_id','limit']),
            $this->fn('teach_decision_engine','Store an explicit operator correction, technique or case precedent for future IVA route assessments. Use when the user says an assessment was wrong, tells you a proven packaging technique, or gives the actual outcome of a case. This does not overwrite authoritative workbook rules.',['lead_id'=>['type'=>['integer','null']],'knowledge_type'=>['type'=>'string'],'original_decision'=>['type'=>['string','null']],'corrected_decision'=>['type'=>['string','null']],'reason'=>['type'=>'string'],'applicability'=>['type'=>['string','null']],'outcome'=>['type'=>['string','null']]],['lead_id','knowledge_type','original_decision','corrected_decision','reason','applicability','outcome']),
            $this->fn('calculate_target_di','Calculate target monthly DI using the company base-fee formula.',['total_debt'=>['type'=>'number'],'dividend_percent'=>['type'=>'number'],'months'=>['type'=>'integer']],['total_debt','dividend_percent','months']),
            $this->fn('search_creditors','Search Jinx creditor records, including stored three-way-call contact details, before adding/changing a debt or when the user asks how to contact a creditor.',['query'=>['type'=>'string']],['query']),
            $this->fn('update_creditor_contact','Store or update durable creditor three-way-call contact details such as telephone number, opening hours and calling notes. Use when the user tells you the number/hours/instructions to use for a creditor.',['creditor_id'=>['type'=>'integer'],'phone'=>['type'=>['string','null']],'opening_hours'=>['type'=>['string','null']],'notes'=>['type'=>['string','null']]],['creditor_id','phone','opening_hours','notes']),
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
            'search_internal_knowledge'=>$this->searchKnowledge($args), 'save_internal_knowledge'=>$this->saveKnowledge($args), 'get_vicidial_state'=>$this->vicidialState($args), 'search_vicidial_leads'=>$this->searchVicidialLeads($args), 'get_vicidial_history'=>$this->vicidialHistory($args), 'inspect_vicidial_campaign'=>$this->inspectVicidialCampaign($args), 'audit_vicidial_hopper'=>$this->auditVicidialHopper($args), 'summarize_vicidial_activity'=>$this->summarizeVicidialActivity($args), 'diagnose_vicidial_performance'=>$this->diagnoseVicidialPerformance($args), 'trace_vicidial_lead_dial_path'=>$this->traceVicidialLeadDialPath($args), 'get_dialler_change_audit'=>$this->getDiallerChangeAudit($args), 'update_vicidial_lead_status'=>$this->updateVicidialStatus($args), 'remove_vicidial_from_hopper'=>$this->removeVicidialHopper($args), 'update_vicidial_comments'=>$this->updateVicidialComments($args), 'check_crm_dialler_consistency'=>$this->checkCrmDiallerConsistency($args), 'repair_crm_dialler_consistency'=>$this->repairCrmDiallerConsistency($args),
            'schedule_callback'=>$this->scheduleCallback($args), 'cancel_callback'=>$this->cancelCallback($args), 'update_wip_status'=>$this->updateStatus($args),
            'add_case_note'=>$this->addNote($args), 'update_case_field'=>$this->updateCaseField($args), 'update_ie_fact'=>$this->updateIeFact($args), 'calculate_ie'=>$this->calculateIe($args), 'review_iva_case'=>$this->reviewIvaCase($args), 'assess_iva_case_decision'=>$this->assessIvaCaseDecision($args), 'analyse_iva_route'=>$this->analyseIvaRoute($args), 'get_decision_case_facts'=>$this->getDecisionCaseFacts($args), 'set_decision_case_fact'=>$this->setDecisionCaseFact($args), 'set_debt_decision_fact'=>$this->setDebtDecisionFact($args), 'record_ie_adjustment'=>$this->recordIeAdjustment($args), 'set_ie_adjustment_status'=>$this->setIeAdjustmentStatus($args), 'assess_property_case'=>$this->assessPropertyCase($args), 'assess_refresh_dmp'=>$this->assessRefreshDmp($args), 'audit_decision_engine_knowledge'=>$this->auditDecisionKnowledge($args), 'record_voting_snapshot'=>$this->recordVotingSnapshot($args), 'record_decision_assessment'=>$this->recordDecisionAssessment($args), 'get_decision_assessments'=>$this->getDecisionAssessments($args), 'teach_decision_engine'=>$this->teachDecisionEngine($args), 'calculate_target_di'=>$this->calculateTargetDi($args), 'search_creditors'=>$this->searchCreditors($args), 'update_creditor_contact'=>$this->updateCreditorContact($args), 'add_debt'=>$this->addDebt($args), 'update_debt'=>$this->updateDebt($args), 'delete_debt'=>$this->deleteDebt($args), 'set_checklist_item'=>$this->setChecklistItem($args), 'search_jinx_code'=>$this->searchCode($args), 'read_jinx_file'=>$this->readCodeFile($args), 'search_laravel_log'=>$this->searchLog($args), default=>throw new RuntimeException('Unknown Jinx agent tool: '.$name),
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
        return ['cases'=>$query->latest('updated_at')->limit(25)->get()->map(fn(Lead $l)=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'status'=>$l->wip_status,'dead_reason'=>$l->dead_reason,'source'=>$l->source,'vicidial_lead_id'=>$l->vicidial_lead_id])->all()];
    }

    private function getCase(array $a): array
    {
        $l=Lead::with('debts.creditor')->findOrFail((int)$a['lead_id']);
        $check=LeadChecklistItem::query()->where('lead_id',$l->id)->get()->map(fn($i)=>['item_id'=>$i->id,'item'=>$i->item_name??('Item '.$i->id),'complete'=>(bool)$i->is_complete,'source_type'=>$i->source_type])->all();
        $cb=collect($this->callbacks->activeForJinxLeads())->firstWhere('lead_id',$l->id);
        return ['case'=>['lead_id'=>$l->id,'name'=>$l->formattedName(),'status'=>$l->wip_status,'dead_reason'=>$l->dead_reason,'source'=>$l->source,'vicidial_lead_id'=>$l->vicidial_lead_id,'employment_status'=>$l->employment_status,'monthly_income'=>$l->monthly_income,'estimated_total_debt'=>$l->estimated_total_debt,'case_notes'=>$l->case_notes,'financial_statement'=>$l->financial_statement,'debts'=>$l->debts->map(fn($d)=>['debt_id'=>$d->id,'creditor_id'=>$d->creditor_id,'creditor'=>$d->creditor?->name,'balance'=>(float)$d->balance,'source_expected'=>$d->source_expected,'reference'=>$d->reference])->all(),'checklist'=>$check,'active_callback'=>$cb]];
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

    private function searchVicidialLeads(array $a): array { $q=trim((string)$a['query']);if($q==='')throw new RuntimeException('Search query is required.');$c=(string)config('services.vicidial.db_connection','asterisk');$query=DB::connection($c)->table('vicidial_list')->where(function($x)use($q){$x->where('phone_number','like','%'.$q.'%')->orWhere('first_name','like','%'.$q.'%')->orWhere('last_name','like','%'.$q.'%')->orWhereRaw("CONCAT(first_name,' ',last_name) like ?",['%'.$q.'%'])->orWhere('vendor_lead_code','like','%'.$q.'%')->orWhere('source_id','like','%'.$q.'%');if(ctype_digit($q))$x->orWhere('lead_id',(int)$q)->orWhere('list_id',(int)$q);});$rows=$query->orderByDesc('lead_id')->limit(30)->get(['lead_id','status','list_id','phone_number','first_name','last_name','vendor_lead_code','source_id','user','called_count','last_local_call_time','comments']);$jinx=Lead::query()->whereIn('vicidial_lead_id',$rows->pluck('lead_id'))->get(['id','vicidial_lead_id','wip_status'])->keyBy(fn($l)=>(int)$l->vicidial_lead_id);return ['matches'=>$rows->map(function($r)use($jinx){$j=$jinx->get((int)$r->lead_id);return array_merge((array)$r,['jinx_lead_id'=>$j?->id,'jinx_wip_status'=>$j?->wip_status]);})->all()]; }

    private function vicidialHistory(array $a): array { $id=(int)$a['vicidial_lead_id'];$limit=max(1,min(100,(int)($a['limit']??30)));$c=(string)config('services.vicidial.db_connection','asterisk');$v=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first();if(!$v)throw new RuntimeException('VICIdial lead not found.');$out=DB::connection($c)->table('vicidial_log')->where('lead_id',$id)->orderByDesc('call_date')->limit($limit)->get(['call_date','campaign_id','status','user','length_in_sec','comments','term_reason','called_count'])->map(fn($r)=>array_merge(['direction'=>'outbound'],(array)$r));$in=DB::connection($c)->table('vicidial_closer_log')->where('lead_id',$id)->orderByDesc('call_date')->limit($limit)->get(['call_date','campaign_id','status','user','length_in_sec','comments','term_reason','called_count'])->map(fn($r)=>array_merge(['direction'=>'inbound'],(array)$r));$history=$out->concat($in)->sortByDesc('call_date')->take($limit)->values();$hopper=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->get(['hopper_id','campaign_id','status','priority','source']);$callbacks=DB::connection($c)->table('vicidial_callbacks')->where('lead_id',$id)->orderByDesc('callback_time')->limit(20)->get(['callback_id','campaign_id','status','callback_time','user','recipient','comments','lead_status']);$list=DB::connection($c)->table('vicidial_lists')->where('list_id',$v->list_id)->first(['list_id','list_name','campaign_id','active','list_description','local_call_time']);return ['lead'=>(array)$v,'list'=>$list?(array)$list:null,'hopper'=>$hopper->map(fn($r)=>(array)$r)->all(),'callbacks'=>$callbacks->map(fn($r)=>(array)$r)->all(),'call_history'=>$history->all(),'call_count_returned'=>$history->count()]; }

    private function inspectVicidialCampaign(array $a): array { $c=(string)config('services.vicidial.db_connection','asterisk');$campaign=trim((string)($a['campaign_id']??''));$listId=(int)($a['list_id']??0);$list=null;if($listId>0){$list=DB::connection($c)->table('vicidial_lists')->where('list_id',$listId)->first();if(!$list)throw new RuntimeException('VICIdial list not found.');if($campaign==='')$campaign=(string)$list->campaign_id;}if($campaign==='')throw new RuntimeException('Campaign ID or list ID is required.');$camp=DB::connection($c)->table('vicidial_campaigns')->where('campaign_id',$campaign)->first(['campaign_id','campaign_name','active','dial_status_a','dial_status_b','dial_status_c','dial_status_d','dial_status_e','dial_statuses','dial_method','hopper_level','auto_dial_level','lead_order','lead_filter_id','no_hopper_dialing','use_auto_hopper','auto_hopper_level','auto_trim_hopper','scheduled_callbacks','scheduled_callbacks_force_dial','scheduled_callbacks_auto_reschedule','manual_dial_list_id','local_call_time']);if(!$camp)throw new RuntimeException('VICIdial campaign not found.');$lists=DB::connection($c)->table('vicidial_lists')->where('campaign_id',$campaign)->where(function($q)use($listId){$q->where('active','Y');if($listId>0)$q->orWhere('list_id',$listId);})->limit(30)->get(['list_id','list_name','active','list_description','local_call_time','reset_time','list_lastcalldate']);$hopperCount=DB::connection($c)->table('vicidial_hopper')->where('campaign_id',$campaign)->count();return ['campaign'=>(array)$camp,'requested_list'=>$list?(array)$list:null,'lists'=>$lists->map(fn($r)=>(array)$r)->all(),'hopper_count'=>$hopperCount]; }

    private function auditVicidialHopper(array $a): array
    {
        $c=(string)config('services.vicidial.db_connection','asterisk');$campaign=trim((string)($a['campaign_id']??''));$q=DB::connection($c)->table('vicidial_hopper as h')->join('vicidial_list as v','v.lead_id','=','h.lead_id');if($campaign!=='')$q->where('h.campaign_id',$campaign);$rows=$q->orderByDesc('h.hopper_id')->limit(1000)->get(['h.hopper_id','h.lead_id','h.campaign_id','h.status as hopper_status','h.priority','h.source','v.status as lead_status','v.list_id','v.phone_number','v.first_name','v.last_name','v.vendor_lead_code']);
        $campaigns=DB::connection($c)->table('vicidial_campaigns')->when($campaign!=='',fn($x)=>$x->where('campaign_id',$campaign))->get(['campaign_id','active','dial_statuses'])->keyBy('campaign_id');$jinx=Lead::query()->whereIn('vicidial_lead_id',$rows->pluck('lead_id'))->get(['id','vicidial_lead_id','wip_status'])->keyBy(fn($l)=>(int)$l->vicidial_lead_id);$protected=['Collecting Docs','Callback Set','DMP Transfer','Ready to Refer','SIP Booked','IVA Verified','DMP Verified','Lost Contact','Dead'];$sus=[];
        foreach($rows as $r){$reasons=[];$j=$jinx->get((int)$r->lead_id);$camp=$campaigns->get($r->campaign_id);$dialable=$camp?preg_split('/\s+/',trim((string)$camp->dial_statuses)):[];if($j&&in_array($j->wip_status,$protected,true))$reasons[]='Jinx stage '.$j->wip_status.' should not be queued';if(in_array($r->lead_status,['WIP','HOLD','CBHOLD','CALLBK'],true))$reasons[]='held VICIdial status '.$r->lead_status.' is in hopper';if($camp&&!in_array($r->lead_status,$dialable,true))$reasons[]='lead status '.$r->lead_status.' is not in campaign dial statuses';if($camp&&$camp->active!=='Y')$reasons[]='campaign is inactive';if($reasons)$sus[]=array_merge((array)$r,['jinx_lead_id'=>$j?->id,'jinx_stage'=>$j?->wip_status,'reasons'=>$reasons]);}
        return ['campaign_id'=>$campaign?:null,'hopper_count'=>$rows->count(),'scanned_limit'=>1000,'suspicious_count'=>count($sus),'suspicious'=>array_slice($sus,0,100),'truncated'=>$rows->count()>=1000];
    }

    private function summarizeVicidialActivity(array $a): array
    {
        $c=(string)config('services.vicidial.db_connection','asterisk');$hours=max(1,min(744,(int)$a['hours']));$since=now()->subHours($hours);$campaign=trim((string)($a['campaign_id']??''));$listId=(int)($a['list_id']??0);$vendor=trim((string)($a['vendor_contains']??''));$q=DB::connection($c)->table('vicidial_log as l')->leftJoin('vicidial_list as v','v.lead_id','=','l.lead_id')->where('l.call_date','>=',$since);if($campaign!=='')$q->where('l.campaign_id',$campaign);if($listId>0)$q->where('l.list_id',$listId);if($vendor!=='')$q->where('v.vendor_lead_code','like','%'.$vendor.'%');
        $total=(clone $q)->count();$seconds=(int)(clone $q)->sum('l.length_in_sec');$unique=(int)(clone $q)->distinct()->count('l.lead_id');$dispos=(clone $q)->selectRaw('l.status, COUNT(*) calls, COUNT(DISTINCT l.lead_id) leads, SUM(l.length_in_sec) seconds')->groupBy('l.status')->orderByDesc('calls')->get()->map(fn($r)=>['status'=>$r->status,'calls'=>(int)$r->calls,'unique_leads'=>(int)$r->leads,'seconds'=>(int)$r->seconds])->all();$agents=(clone $q)->selectRaw('l.user, COUNT(*) calls')->groupBy('l.user')->orderByDesc('calls')->limit(30)->get()->map(fn($r)=>['user'=>$r->user,'calls'=>(int)$r->calls])->all();return ['period_hours'=>$hours,'since'=>$since->toDateTimeString(),'filters'=>['campaign_id'=>$campaign?:null,'list_id'=>$listId?:null,'vendor_contains'=>$vendor?:null],'total_calls'=>$total,'unique_leads'=>$unique,'total_call_seconds'=>$seconds,'dispositions'=>$dispos,'agents'=>$agents];
    }

    private function diagnoseVicidialPerformance(array $a): array
    {
        $c=(string)config('services.vicidial.db_connection','asterisk');$hours=max(1,min(336,(int)$a['hours']));$campaign=trim((string)($a['campaign_id']??''));$listId=(int)($a['list_id']??0);$vendor=trim((string)($a['vendor_contains']??''));$end=now();$start=$end->copy()->subHours($hours);$prevStart=$start->copy()->subHours($hours);
        $build=function($from,$to)use($c,$campaign,$listId,$vendor){$q=DB::connection($c)->table('vicidial_log as l')->leftJoin('vicidial_list as v','v.lead_id','=','l.lead_id')->where('l.call_date','>=',$from)->where('l.call_date','<',$to);if($campaign!=='')$q->where('l.campaign_id',$campaign);if($listId>0)$q->where('l.list_id',$listId);if($vendor!=='')$q->where('v.vendor_lead_code','like','%'.$vendor.'%');$calls=(int)(clone $q)->count();$unique=(int)(clone $q)->distinct()->count('l.lead_id');$seconds=(int)(clone $q)->sum('l.length_in_sec');$disp=(clone $q)->selectRaw('l.status,COUNT(*) calls,COUNT(DISTINCT l.lead_id) leads,SUM(l.length_in_sec) seconds')->groupBy('l.status')->orderByDesc('calls')->get()->mapWithKeys(fn($r)=>[$r->status=>['calls'=>(int)$r->calls,'unique_leads'=>(int)$r->leads,'seconds'=>(int)$r->seconds]])->all();return ['calls'=>$calls,'unique_leads'=>$unique,'seconds'=>$seconds,'avg_seconds_per_call'=>$calls?round($seconds/$calls,1):0,'dispositions'=>$disp];};
        $current=$build($start,$end);$previous=$build($prevStart,$start);$pct=fn($n,$o)=>$o?round((($n-$o)/$o)*100,1):($n?null:0);$flags=[];$callChange=$pct($current['calls'],$previous['calls']);if($callChange!==null&&abs($callChange)>=25)$flags[]=['type'=>'volume_change','message'=>'Call volume changed '.$callChange.'% versus the preceding '.$hours.'h period.'];$avgChange=$pct($current['avg_seconds_per_call'],$previous['avg_seconds_per_call']);if($avgChange!==null&&abs($avgChange)>=30)$flags[]=['type'=>'duration_change','message'=>'Average call duration changed '.$avgChange.'%.'];foreach($current['dispositions'] as $status=>$d){$old=$previous['dispositions'][$status]['calls']??0;$change=$pct($d['calls'],$old);if($d['calls']>=5&&$change!==null&&abs($change)>=50)$flags[]=['type'=>'disposition_change','status'=>$status,'message'=>$status.' calls changed '.$change.'%.'];}$hopper=$this->auditVicidialHopper(['campaign_id'=>$campaign?:null]);if($hopper['suspicious_count']>0)$flags[]=['type'=>'hopper_integrity','message'=>$hopper['suspicious_count'].' suspicious hopper lead(s) currently detected.'];
        return ['period_hours'=>$hours,'filters'=>['campaign_id'=>$campaign?:null,'list_id'=>$listId?:null,'vendor_contains'=>$vendor?:null],'current_period'=>['from'=>$start->toDateTimeString(),'to'=>$end->toDateTimeString()]+$current,'previous_period'=>['from'=>$prevStart->toDateTimeString(),'to'=>$start->toDateTimeString()]+$previous,'changes'=>['calls_percent'=>$callChange,'unique_leads_percent'=>$pct($current['unique_leads'],$previous['unique_leads']),'avg_duration_percent'=>$avgChange],'operational_flags'=>$flags,'hopper_health'=>['count'=>$hopper['hopper_count'],'suspicious'=>$hopper['suspicious_count']],'interpretation_note'=>'Flags identify measured changes, not proven causes. Drill into lead history/campaign configuration before attributing causation.'];
    }

    private function traceVicidialLeadDialPath(array $a): array
    {
        $id=(int)$a['vicidial_lead_id'];$c=(string)config('services.vicidial.db_connection','asterisk');$v=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first(['lead_id','status','list_id','phone_number','first_name','last_name','vendor_lead_code','source_id','user','called_count','called_since_last_reset','last_local_call_time','modify_date']);if(!$v)throw new RuntimeException('VICIdial lead not found.');
        $list=DB::connection($c)->table('vicidial_lists')->where('list_id',$v->list_id)->first(['list_id','list_name','campaign_id','active','local_call_time','list_lastcalldate']);$campaign=$list?DB::connection($c)->table('vicidial_campaigns')->where('campaign_id',$list->campaign_id)->first(['campaign_id','campaign_name','active','dial_statuses','dial_method','hopper_level','auto_dial_level','lead_order','lead_filter_id','no_hopper_dialing','use_auto_hopper','auto_hopper_level','auto_trim_hopper','scheduled_callbacks','scheduled_callbacks_force_dial']):null;
        $hopper=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->get(['hopper_id','campaign_id','status','user','list_id','alt_dial','priority','source','vendor_lead_code']);$callbacks=DB::connection($c)->table('vicidial_callbacks')->where('lead_id',$id)->orderByDesc('callback_time')->limit(10)->get(['callback_id','campaign_id','status','callback_time','user','recipient','comments','lead_status']);$calls=DB::connection($c)->table('vicidial_log')->where('lead_id',$id)->orderByDesc('call_date')->limit(20)->get(['call_date','campaign_id','status','user','length_in_sec','comments','term_reason','called_count']);$jinx=Lead::query()->where('vicidial_lead_id',$id)->first(['id','wip_status','updated_at']);
        $dialStatuses=$campaign?array_values(array_filter(preg_split('/\s+/',trim((string)$campaign->dial_statuses)))):[];$protected=['Collecting Docs','Callback Set','DMP Transfer','Ready to Refer','SIP Booked','IVA Verified','DMP Verified','Lost Contact','Dead'];$eligible=$campaign&&$campaign->active==='Y'&&$list&&$list->active==='Y'&&in_array($v->status,$dialStatuses,true);$evidence=[];$evidence[]=['fact'=>'lead_status','value'=>$v->status,'effect'=>in_array($v->status,$dialStatuses,true)?'matches campaign dial statuses':'does not match campaign dial statuses'];if($list)$evidence[]=['fact'=>'list','value'=>$list->list_id.' '.$list->list_name,'effect'=>$list->active==='Y'?'active':'inactive'];if($campaign)$evidence[]=['fact'=>'campaign','value'=>$campaign->campaign_id,'effect'=>'active='.$campaign->active.', dial_method='.$campaign->dial_method.', dial_statuses='.implode(' ',$dialStatuses)];if($jinx)$evidence[]=['fact'=>'jinx_stage','value'=>$jinx->wip_status,'effect'=>in_array($jinx->wip_status,$protected,true)?'Jinx says this case should not be auto-dialled':'Jinx permits New Lead dial workflow'];
        $sourceMeaning=['S'=>'standard hopper selection by AST_VDhopper','N'=>'new-lead hopper selection by AST_VDhopper','C'=>'scheduled callback insertion','A'=>'alternate-number insertion','Q'=>'agent/manual queue insertion','D'=>'hopper drop/priority handling'];foreach($hopper as $h)$evidence[]=['fact'=>'hopper_row','value'=>$h->hopper_id,'effect'=>'source '.$h->source.' = '.($sourceMeaning[$h->source]??'VICIdial internal source').'; status='.$h->status];$conflict=$jinx&&in_array($jinx->wip_status,$protected,true)&&($eligible||$hopper->isNotEmpty());
        $mechanism=$eligible?'AST_VDhopper runs every minute on this server and selects leads whose VICIdial status is in the active campaign dial statuses and whose list/campaign constraints pass. Removing only the hopper row is temporary if the underlying VICIdial status remains dialable; the next hopper run can select it again.':'Current lead/list/campaign state does not satisfy the basic standard auto-hopper eligibility check.';
        return ['vicidial_lead'=>(array)$v,'jinx_case'=>$jinx?['lead_id'=>$jinx->id,'stage'=>$jinx->wip_status,'updated_at'=>(string)$jinx->updated_at]:null,'list'=>$list?(array)$list:null,'campaign'=>$campaign?(array)$campaign:null,'campaign_dial_statuses'=>$dialStatuses,'currently_standard_hopper_eligible'=>$eligible,'crm_dialler_conflict'=>$conflict,'current_hopper'=>$hopper->map(fn($x)=>(array)$x)->all(),'callbacks'=>$callbacks->map(fn($x)=>(array)$x)->all(),'recent_calls'=>$calls->map(fn($x)=>(array)$x)->all(),'evidence'=>$evidence,'selection_mechanism'=>$mechanism,'causation_limit'=>'VICIdial hopper is a MEMORY table and does not retain deleted historical rows. Current source/status/configuration and call history can establish the selection path, but an exact historical insertion timestamp cannot be proven after that hopper row is gone unless separate debug logging captured it.'];
    }

    private function updateVicidialStatus(array $a): array { $id=(int)$a['vicidial_lead_id'];$status=strtoupper(trim((string)$a['status']));if($status===''||strlen($status)>6||!preg_match('/^[A-Z0-9_]+$/',$status))throw new RuntimeException('Invalid VICIdial status.');$c=(string)config('services.vicidial.db_connection','asterisk');$row=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first(['lead_id','status']);if(!$row)throw new RuntimeException('VICIdial lead not found.');$valid=DB::connection($c)->table('vicidial_statuses')->where('status',$status)->exists()||DB::connection($c)->table('vicidial_campaign_statuses')->where('status',$status)->exists();if(!$valid)throw new RuntimeException('Unknown VICIdial status.');DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->update(['status'=>$status]);$removed=0;if(in_array($status,['WIP','HOLD','CBHOLD','CALLBK'],true))$removed=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->delete();$result=['success'=>true,'vicidial_lead_id'=>$id,'old_status'=>$row->status,'new_status'=>$status,'hopper_rows_removed'=>$removed];$this->auditDiallerChange('update_status',$id,['status'=>$row->status],['status'=>$status,'hopper_rows_removed'=>$removed]);return $result; }
    private function removeVicidialHopper(array $a): array { $id=(int)$a['vicidial_lead_id'];$c=(string)config('services.vicidial.db_connection','asterisk');if(!DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->exists())throw new RuntimeException('VICIdial lead not found.');$n=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->delete();$this->auditDiallerChange('remove_hopper',$id,['hopper_rows'=>$n],['hopper_rows'=>0]);return ['success'=>true,'vicidial_lead_id'=>$id,'hopper_rows_removed'=>$n]; }
    private function updateVicidialComments(array $a): array { $id=(int)$a['vicidial_lead_id'];$comments=trim((string)$a['comments']);$c=(string)config('services.vicidial.db_connection','asterisk');$row=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first(['lead_id','comments']);if(!$row)throw new RuntimeException('VICIdial lead not found.');DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->update(['comments'=>$comments]);$this->auditDiallerChange('update_comments',$id,['comments'=>$row->comments],['comments'=>$comments]);return ['success'=>true,'vicidial_lead_id'=>$id,'old_comments'=>$row->comments,'new_comments'=>$comments]; }

    private function checkCrmDiallerConsistency(array $a): array
    {
        $lead=Lead::findOrFail((int)$a['lead_id']);$c=(string)config('services.vicidial.db_connection','asterisk');$issues=[];
        if(!$lead->vicidial_lead_id)return ['lead_id'=>$lead->id,'consistent'=>false,'issues'=>[['code'=>'missing_vicidial_link','message'=>'Jinx case has no VICIdial lead ID.']],'safe_repair_available'=>false];
        $v=DB::connection($c)->table('vicidial_list')->where('lead_id',(int)$lead->vicidial_lead_id)->first(['lead_id','status','list_id','phone_number','user','called_count','last_local_call_time']);
        if(!$v)return ['lead_id'=>$lead->id,'vicidial_lead_id'=>(int)$lead->vicidial_lead_id,'consistent'=>false,'issues'=>[['code'=>'broken_vicidial_link','message'=>'Linked VICIdial lead does not exist.']],'safe_repair_available'=>false];
        $hopper=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$v->lead_id)->get(['hopper_id','campaign_id','status','priority']);
        $callbacks=DB::connection($c)->table('vicidial_callbacks')->where('lead_id',$v->lead_id)->whereIn('status',['ACTIVE','LIVE'])->get(['callback_id','status','callback_time','user','comments']);
        $protectedJinx=in_array($lead->wip_status,['Collecting Docs','Callback Set','DMP Transfer','Ready to Refer','SIP Booked','IVA Verified','DMP Verified','Lost Contact','Dead'],true);$protectedVic=in_array($v->status,['WIP','HOLD','CBHOLD','CALLBK'],true);
        if($protectedJinx&&$hopper->isNotEmpty())$issues[]=['code'=>'protected_case_in_hopper','message'=>'Jinx case is '.$lead->wip_status.' but the VICIdial lead is still in the hopper.'];
        if($protectedVic&&$hopper->isNotEmpty())$issues[]=['code'=>'protected_status_in_hopper','message'=>'VICIdial status '.$v->status.' should not remain in the hopper.'];
        if($callbacks->isNotEmpty()&&!in_array($v->status,['CBHOLD','CALLBK'],true))$issues[]=['code'=>'callback_status_mismatch','message'=>'An active/live callback exists but VICIdial status is '.$v->status.'.'];
        if($callbacks->isEmpty()&&in_array($v->status,['CBHOLD','CALLBK'],true))$issues[]=['code'=>'orphan_callback_status','message'=>'VICIdial status is '.$v->status.' but no active/live callback exists.'];
        if($lead->wip_status==='New Lead'&&!$callbacks->count()&&in_array($v->status,['WIP','HOLD','CBHOLD','CALLBK'],true))$issues[]=['code'=>'new_lead_held','message'=>'Jinx is New Lead but VICIdial is held as '.$v->status.'.'];
        return ['lead_id'=>$lead->id,'jinx_status'=>$lead->wip_status,'vicidial_lead_id'=>(int)$v->lead_id,'vicidial_status'=>$v->status,'hopper_rows'=>$hopper->map(fn($x)=>(array)$x)->all(),'active_callbacks'=>$callbacks->map(fn($x)=>(array)$x)->all(),'consistent'=>count($issues)===0,'issues'=>$issues,'safe_repair_available'=>count($issues)>0];
    }

    private function repairCrmDiallerConsistency(array $a): array
    {
        $before=$this->checkCrmDiallerConsistency($a);if(empty($before['vicidial_lead_id']))throw new RuntimeException('This case has no repairable VICIdial link.');$lead=Lead::findOrFail((int)$a['lead_id']);$id=(int)$before['vicidial_lead_id'];$c=(string)config('services.vicidial.db_connection','asterisk');$actions=[];
        $hasCallback=!empty($before['active_callbacks']);$protectedJinx=in_array($lead->wip_status,['Collecting Docs','Callback Set','DMP Transfer','Ready to Refer','SIP Booked','IVA Verified','DMP Verified','Lost Contact','Dead'],true);
        if($protectedJinx||$hasCallback){$n=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$id)->delete();if($n)$actions[]="removed {$n} hopper row(s)";}
        $v=DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->first(['status']);
        if($hasCallback&&!in_array($v->status,['CBHOLD','CALLBK'],true)){DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->update(['status'=>'CBHOLD']);$actions[]="changed VICIdial status {$v->status} -> CBHOLD";}
        elseif(!$hasCallback&&in_array($v->status,['CBHOLD','CALLBK'],true)){DB::connection($c)->table('vicidial_list')->where('lead_id',$id)->update(['status'=>'WIP']);$actions[]="cleared orphan callback status {$v->status} -> WIP";}
        $after=$this->checkCrmDiallerConsistency($a);return ['success'=>true,'actions'=>$actions,'before'=>$before,'after'=>$after];
    }

    private function scheduleCallback(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$when=Carbon::parse((string)$a['callback_at']);if($when->isPast())throw new RuntimeException('Callback time is in the past.');$r=$this->callbacks->schedule($l,$when,(string)($a['notes']??''));$this->auditDiallerChange('schedule_callback',(int)$l->vicidial_lead_id,null,$r,$l->id);return ['success'=>true,'result'=>$r]; }
    private function cancelCallback(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$r=$this->callbacks->cancel($l,(string)($a['reason']??''));$this->auditDiallerChange('cancel_callback',(int)$l->vicidial_lead_id,null,$r,$l->id);return ['success'=>true,'result'=>$r]; }
    private function updateStatus(array $a): array
    { $l=Lead::findOrFail((int)$a['lead_id']);$status=(string)$a['status'];if(!in_array($status,Lead::WIP_STATUSES,true))throw new RuntimeException('Invalid WIP status.');$reason=trim((string)($a['reason']??''));if($status==='Dead'&&$reason==='')throw new RuntimeException('A reason is required when marking a case Dead.');$old=$l->wip_status;$l->update(['wip_status'=>$status,'dead_reason'=>$status==='Dead'?$reason:null]);$removed=0;$diallerStatus=null;if($l->vicidial_lead_id&&in_array($status,['Collecting Docs','Callback Set','DMP Transfer','Ready to Refer','SIP Booked','IVA Verified','DMP Verified','Lost Contact','Dead'],true)){$c=(string)config('services.vicidial.db_connection','asterisk');$vid=(int)$l->vicidial_lead_id;$hasCallback=DB::connection($c)->table('vicidial_callbacks')->where('lead_id',$vid)->whereIn('status',['ACTIVE','LIVE'])->exists();$diallerStatus=$hasCallback?'CBHOLD':'WIP';DB::connection($c)->table('vicidial_list')->where('lead_id',$vid)->update(['status'=>$diallerStatus]);$removed=DB::connection($c)->table('vicidial_hopper')->where('lead_id',$vid)->delete();}return ['success'=>true,'lead_id'=>$l->id,'old_status'=>$old,'new_status'=>$status,'dead_reason'=>$l->dead_reason,'vicidial_status'=>$diallerStatus,'hopper_rows_removed'=>$removed]; }
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
    private function assessIvaCaseDecision(array $a): array {return $this->decisionEngine->assess(Lead::findOrFail((int)$a['lead_id']));}
    private function analyseIvaRoute(array $a): array {return $this->iva->analyseRoute(Lead::findOrFail((int)$a['lead_id']),(string)$a['destination']);}
    private function getDecisionCaseFacts(array $a): array
    {
        $lead=Lead::findOrFail((int)$a['lead_id']);
        return $this->decisionFacts->allForLead($lead)+['ie_adjustments'=>$this->decisionFacts->ieAdjustmentSummary($lead),'case_fact_keys'=>DecisionCaseFactService::caseKeys(),'debt_fact_keys'=>DecisionCaseFactService::debtKeys()];
    }
    private function setDecisionCaseFact(array $a): array
    {
        return $this->decisionFacts->setLeadFact(
            Lead::findOrFail((int)$a['lead_id']),
            (string)$a['key'],
            $a['value'],
            'operator',
            filled($a['source_detail']??null)?(string)$a['source_detail']:null
        );
    }
    private function setDebtDecisionFact(array $a): array
    {
        return $this->decisionFacts->setDebtFact(
            Debt::findOrFail((int)$a['debt_id']),
            (string)$a['key'],
            $a['value'],
            'operator',
            filled($a['source_detail']??null)?(string)$a['source_detail']:null
        );
    }
    private function recordIeAdjustment(array $a): array
    {
        return $this->decisionFacts->recordIeAdjustment(
            Lead::findOrFail((int)$a['lead_id']),
            (string)$a['section_key'],
            (float)$a['original_amount'],
            (float)$a['proposed_amount'],
            (string)$a['reason'],
            !empty($a['rule_id'])?(int)$a['rule_id']:null,
            filled($a['evidence']??null)?(string)$a['evidence']:null,
            (string)$a['status']
        );
    }
    private function setIeAdjustmentStatus(array $a): array
    {
        return $this->decisionFacts->setIeAdjustmentStatus(
            Lead::findOrFail((int)$a['lead_id']),
            (int)$a['adjustment_id'],
            (string)$a['status']
        );
    }
    private function assessPropertyCase(array $a): array
    {
        $key=filled($a['destination']??null)?$this->iva->destinationKey((string)$a['destination']):null;
        return $this->propertyDecision->evaluate(Lead::findOrFail((int)$a['lead_id']),$key);
    }
    private function assessRefreshDmp(array $a): array
    {
        $lead=Lead::findOrFail((int)$a['lead_id']);
        $review=$this->iva->review($lead);
        return $this->dmpDecision->evaluate($lead,$review['ie']);
    }
    private function auditDecisionKnowledge(array $a): array {return $this->iva->auditDecisionKnowledge(!empty($a['lead_id'])?Lead::findOrFail((int)$a['lead_id']):null,filled($a['destination']??null)?(string)$a['destination']:null);}
    private function recordVotingSnapshot(array $a): array {return $this->iva->recordVotingSnapshot(Lead::findOrFail((int)$a['lead_id']),(string)$a['destination']);}
    private function recordDecisionAssessment(array $a): array
    {
        return $this->decisionEngine->recordDecision(
            Lead::findOrFail((int)$a['lead_id']),
            (string)$a['preferred_route'],
            (string)$a['status'],
            (string)$a['rationale'],
            filled($a['actions']??null)?(string)$a['actions']:null
        );
    }

    private function getDecisionAssessments(array $a): array
    {
        return ['assessments'=>$this->decisionEngine->assessments(Lead::findOrFail((int)$a['lead_id']),(int)$a['limit'])];
    }

    private function teachDecisionEngine(array $a): array
    {
        $type = strtolower(trim((string)$a['knowledge_type']));
        $allowed = ['correction','technique','precedent','failed_precedent','outcome'];
        if (!in_array($type,$allowed,true)) throw new RuntimeException('knowledge_type must be correction, technique, precedent, failed_precedent or outcome.');
        $reason = trim((string)$a['reason']);
        if ($reason === '') throw new RuntimeException('A reason is required.');
        $leadId = isset($a['lead_id']) && $a['lead_id'] !== null ? (int)$a['lead_id'] : null;
        $context = null;
        if ($leadId) {
            $lead = Lead::findOrFail($leadId);
            $review = $this->iva->review($lead);
            $context = [
                'lead_id'=>$leadId,
                'source'=>$lead->source,
                'known_debt_total'=>$review['known_debt_total'],
                'profile'=>$review['profile'],
                'voting_house_exposure'=>$review['voting_house_exposure'],
                'decision_facts'=>$this->decisionFacts->allForLead($lead),
                'ie_adjustments'=>$this->decisionFacts->ieAdjustmentSummary($lead),
                'ie_calculation'=>data_get($review,'ie.calculation'),
            ];
        }
        $applicability = trim((string)($a['applicability'] ?? ''));
        $id = DB::table('decision_learning_records')->insertGetId([
            'lead_id'=>$leadId, 'knowledge_type'=>$type, 'status'=>'active',
            'original_decision'=>filled($a['original_decision']??null)?trim((string)$a['original_decision']):null,
            'corrected_decision'=>filled($a['corrected_decision']??null)?trim((string)$a['corrected_decision']):null,
            'reason'=>$reason, 'case_context'=>$context ? json_encode($context) : null,
            'applicability'=>$applicability !== '' ? json_encode(['description'=>$applicability]) : null,
            'outcome'=>filled($a['outcome']??null)?trim((string)$a['outcome']):null,
            'times_confirmed'=>1, 'last_confirmed_at'=>now(), 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        return ['success'=>true,'learning_record_id'=>$id,'knowledge_type'=>$type,'lead_id'=>$leadId,'stored_as'=>'operator_learning','authoritative_rule_changed'=>false];
    }

    private function calculateTargetDi(array $a): array {return $this->iva->targetDi((float)$a['total_debt'],(float)$a['dividend_percent'],(int)$a['months']);}
    private function searchCreditors(array $a): array
    { $q=trim((string)$a['query']);return ['creditors'=>Creditor::query()->where('name','like','%'.$q.'%')->orWhereHas('aliases',fn($x)=>$x->where('alias','like','%'.$q.'%'))->limit(20)->get()->map(fn($c)=>['creditor_id'=>$c->id,'name'=>$c->name,'contact_phone'=>$c->contact_phone,'contact_hours'=>$c->contact_hours,'contact_notes'=>$c->contact_notes,'voting_house'=>$c->voting_house,'voting_practices'=>array_values(array_filter([$c->voting_practice1,$c->voting_practice2,$c->voting_practice3]))])->all()]; }
    private function updateCreditorContact(array $a): array
    { $c=Creditor::findOrFail((int)$a['creditor_id']);$c->update(['contact_phone'=>filled($a['phone']??null)?trim((string)$a['phone']):null,'contact_hours'=>filled($a['opening_hours']??null)?trim((string)$a['opening_hours']):null,'contact_notes'=>filled($a['notes']??null)?trim((string)$a['notes']):null]);return ['success'=>true,'creditor_id'=>$c->id,'name'=>$c->name,'contact_phone'=>$c->contact_phone,'contact_hours'=>$c->contact_hours,'contact_notes'=>$c->contact_notes]; }
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

    private function auditDiallerChange(string $action,int $vicidialLeadId,?array $before,?array $after,?int $leadId=null): void
    { DB::table('jinx_agent_dialler_audits')->insert(['lead_id'=>$leadId ?: Lead::where('vicidial_lead_id',$vicidialLeadId)->value('id'),'vicidial_lead_id'=>$vicidialLeadId,'action'=>$action,'before_json'=>$before?json_encode($before):null,'after_json'=>$after?json_encode($after):null,'created_at'=>now(),'updated_at'=>now()]); }

    private function getDiallerChangeAudit(array $a): array
    { $q=DB::table('jinx_agent_dialler_audits')->orderByDesc('id');if(!empty($a['lead_id']))$q->where('lead_id',(int)$a['lead_id']);if(!empty($a['vicidial_lead_id']))$q->where('vicidial_lead_id',(int)$a['vicidial_lead_id']);$limit=max(1,min(100,(int)$a['limit']));return ['changes'=>$q->limit($limit)->get()->map(fn($r)=>['id'=>$r->id,'lead_id'=>$r->lead_id,'vicidial_lead_id'=>$r->vicidial_lead_id,'action'=>$r->action,'before'=>$r->before_json?json_decode($r->before_json,true):null,'after'=>$r->after_json?json_decode($r->after_json,true):null,'at'=>$r->created_at])->all()]; }

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
