<?php

namespace App\Services;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class JinxAssistantService
{
    public function __construct(
        private readonly PartnerKnowledgeService $partnerKnowledge,
        private readonly DestinationSuitabilityService $destinationSuitability,
        private readonly ProactiveRoutingService $proactiveRouting,
        private readonly AssistantIeCalculationService $ieCalculator,
        private readonly ZebraIeInterviewAnswerService $zebraAnswers,
        private readonly AssistantDebtImportService $debtImporter,
    ) {}

    public function reply(AssistantConversation $conversation,string $message,?array $workspaceContext=null): array
    {
        $conversation->loadMissing('lead');

        // Debt imports are deterministic CRM actions. The model never chooses creditor IDs or
        // claims a debt was written. Missing creditors are completed conversationally first.
        $metadata=$conversation->metadata??[];
        $pendingDebt=data_get($metadata,'pending_debt_import');
        if($conversation->lead && is_array($pendingDebt)) {
            $debtResult=$this->debtImporter->continue($conversation->lead,$pendingDebt,$message);
            if(is_array($debtResult['pending']??null)) $metadata['pending_debt_import']=$debtResult['pending'];
            else unset($metadata['pending_debt_import']);
            $conversation->metadata=$metadata;$conversation->save();
            return $this->directActionResult((string)$debtResult['reply'], !is_array($debtResult['pending'] ?? null));
        }
        if($conversation->lead && $this->debtImporter->looksLikeImport($message)) {
            $debtResult=$this->debtImporter->begin($conversation->lead,$message);
            if(($debtResult['handled']??false)===true) {
                if(is_array($debtResult['pending']??null)) $metadata['pending_debt_import']=$debtResult['pending'];
                else unset($metadata['pending_debt_import']);
                $conversation->metadata=$metadata;$conversation->save();
                return $this->directActionResult((string)$debtResult['reply'], !is_array($debtResult['pending'] ?? null));
            }
        }

        $apiKey=(string)config('services.jinx_assistant.api_key');$model=(string)config('services.jinx_assistant.model');
        if($apiKey===''||$model==='')throw new RuntimeException('Jinx Assistant is not configured. Set JINX_ASSISTANT_API_KEY and JINX_ASSISTANT_MODEL.');

        $facts=$this->establishedFacts($conversation);
        $profile=$this->partnerKnowledge->profile($conversation->lead?->source,$facts);

        if($conversation->lead && ($profile['partner']??null)==='Zebra') {
            $direct=$this->zebraAnswers->extract($conversation->lead,$facts,$message);
            if($direct!==[]) $facts=array_replace($facts,$direct);
        }

        $nextIeQuestion=(($profile['partner']??null)==='Zebra' && (($facts['workflow.ie_active']??false)===true))
            ? $this->zebraAnswers->nextQuestion($facts)
            : null;

        $knowledge=$this->knowledgeForProfile($profile);
        $history=$conversation->messages()->latest('id')->limit(24)->get()->reverse()->values()->map(fn(AssistantMessage $item)=>['role'=>$item->role,'content'=>$item->content])->all();
        $similarCases=$this->findSimilarCases($conversation,$message);
        $pendingKnowledge=data_get($conversation->metadata,'pending_knowledge');
        $deterministicBefore=$this->ieCalculator->snapshot($facts,$profile);
        $comparisonRequested=$this->destinationSuitability->shouldCompare($message);
        $destinationComparison=$comparisonRequested?$this->destinationSuitability->context():null;

        $instructions=<<<'PROMPT'
You are Jinx Assistant, an expert IVA case-packaging colleague used by trained case packagers inside the Jinx CRM. The user is the case packager, not the client.

The applicable partner/IP markdown codex is authoritative for the I&E interview. Extract facts accurately, but do not invent a different question order or skip required manual-input questions.

FACT CAPTURE IS NOT I&E START
Supplying case facts does not start an I&E. If workflow.ie_active is not true, capture supplied facts only and ask whether the packager wants the I&E carried out. Do not assess DI, affordability, suitability or routing before explicit confirmation.

FACT STORAGE
Return every clear case fact in fact_updates using stable dot-notated keys. Never re-ask established facts. Treat typed equivalents such as 0/none/no consistently in the context of the question asked.

ZEBRA INCOME RULES
Child Benefit is calculator-owned and must not be asked. Universal Credit is a separate mandatory manual-input checkpoint in the Zebra interview when no amount has already been established. Do not infer UC = 0 from silence. The grouped secondary-income screen does not include Universal Credit and cannot substitute for the UC question.

CALCULATED ITEMS
Never ask for rule-driven SFS figures, Zebra utility figures, TV Licence, Child Benefit or other calculator-owned/default amounts merely because CRM fields are blank.

CONVERSATIONAL I&E
When NEXT_REQUIRED_IE_QUESTION is present, it is the single deterministic checkpoint that still needs answering. Sound like an experienced colleague rather than a form: briefly acknowledge any useful context the packager gave you, then ask that one required question naturally. You may rephrase it conversationally, but do not change what fact is being requested and do not stack extra questions onto it. If the packager gives extra information alongside the answer, acknowledge it and preserve any clear fact updates rather than ignoring the context.

TRAINING / NEW RULES
Durable rules must be proposed at company, partner or IP scope and confirmed before saving. Do not save case facts as organisational knowledge.

CRM ACTIONS
Understand operational requests naturally from the whole conversation, including follow-up answers to your own questions. For a callback on the current case, when the intended date and time are known, return requested_action with type schedule_callback, callback_at as an unambiguous ISO-like local datetime (YYYY-MM-DD HH:MM:SS), and concise notes preserving the reason/purpose. If date or time is genuinely missing, ask for only the missing detail and return requested_action null. Resolve ordinary phrases such as Friday, tomorrow, Friday afternoon, at 2, etc. from CURRENT_LOCAL_DATETIME. Never claim that a callback or other CRM action has been completed yourself. The application executes actions after your response and will replace your wording with a success confirmation only after the write succeeds.

Return ONLY valid JSON:
{"reply":"natural-language reply","fact_updates":{},"suitability_assessment":null,"proposed_knowledge":null,"confirm_pending_knowledge":false,"requested_action":null,"case_summary":"brief rolling summary"}
PROMPT;

        $context=['CURRENT_LOCAL_DATETIME'=>now()->toIso8601String(),'CURRENT_LEAD'=>$this->leadContext($conversation),'PARTNER_PROFILE'=>$profile,'PARTNER_CODEX'=>$profile['partner_codex']??null,'ACTIVE_SCOPED_KNOWLEDGE'=>$knowledge->values()->toArray(),'DESTINATION_COMPARISON'=>$destinationComparison,'ESTABLISHED_FACTS'=>$facts,'DETERMINISTIC_IE'=>$deterministicBefore,'NEXT_REQUIRED_IE_QUESTION'=>$nextIeQuestion,'PENDING_KNOWLEDGE_PROPOSAL'=>$pendingKnowledge,'POTENTIALLY_SIMILAR_PRIOR_CASES'=>$similarCases,'CONVERSATION_HISTORY'=>$history,'LATEST_PACKAGER_MESSAGE'=>$message,'WORKSPACE_CONTEXT'=>$workspaceContext];
        $response=Http::timeout(60)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses',['model'=>$model,'instructions'=>$instructions,'input'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'max_output_tokens'=>$comparisonRequested?2600:1800]);
        if(!$response->successful())throw new RuntimeException('Assistant provider error: '.$response->status().' '.$response->body());
        $decoded=json_decode($this->stripCodeFence($this->extractOutputText($response->json())),true);
        if(!is_array($decoded)||!isset($decoded['reply']))throw new RuntimeException('Assistant returned an invalid response format.');

        $factUpdates=$this->normaliseFactUpdates($decoded['fact_updates']??[]);
        if(isset($direct) && $direct!==[]) $factUpdates=array_replace($factUpdates,$direct);
        if(!(($facts['workflow.ie_active']??false)===true) && $this->acceptedIeOffer($history,$message)) $factUpdates['workflow.ie_active']=true;

        $factsAfter=array_replace($facts,$factUpdates);
        $profileAfter=$this->partnerKnowledge->profile($conversation->lead?->source,$factsAfter);
        $reply=trim((string)$decoded['reply']);
        $suitability=$this->normaliseSuitabilityAssessment($decoded['suitability_assessment']??null);

        if(($profileAfter['partner']??null)==='Zebra' && (($factsAfter['workflow.ie_active']??false)===true)) {
            $next=$this->zebraAnswers->nextQuestion($factsAfter);
            $incomeComplete=$this->zebraAnswers->incomeComplete($factsAfter);
            $factUpdates['workflow.income_complete']=$incomeComplete;$factsAfter['workflow.income_complete']=$incomeComplete;
            if($next!==null){
                $factUpdates['workflow.ie_complete']=false;
                $factsAfter['workflow.ie_complete']=false;
                if($nextIeQuestion===null || $nextIeQuestion!==$next || !str_contains($reply,'?')) $reply=$next;
                $suitability=null;
            }
            else{$factUpdates['workflow.ie_complete']=true;$factsAfter['workflow.ie_complete']=true;}
        }

        $deterministicAfter=$this->ieCalculator->snapshot($factsAfter,$profileAfter);
        if(($profileAfter['partner']??null)==='Zebra' && (($factsAfter['workflow.ie_complete']??false)===true)){$reply=$this->conciseIeCompletion($deterministicAfter);$suitability=null;}
        $factsForRouting=$factsAfter;
        if(($factsAfter['workflow.ie_complete']??false)===true){$factsForRouting['calculation.disposable_income']=$deterministicAfter['calculation']['disposable_income']??null;$factsForRouting['calculation.target_di']=$deterministicAfter['calculation']['target_di']??null;$factsForRouting['income.total']=$deterministicAfter['calculation']['income_total']??null;}
        $proactiveSignature=null;
        // Completed I&Es now move into the sourced whole-case decision engine in the lead-page workflow.
        if(!$comparisonRequested && (($factsAfter['workflow.ie_complete']??false)!==true)){$routeAlert=$this->proactiveRouting->evaluate($profileAfter,$factsForRouting,$conversation->lead);if($routeAlert){$signature=sha1(json_encode($routeAlert,JSON_UNESCAPED_SLASHES));if($signature!==(string)data_get($conversation->metadata,'last_proactive_route_signature','')){$proactive=$this->runProactiveComparison($apiKey,$model,$factsForRouting,$routeAlert);if($proactive){$reply.="\n\n".$proactive['reply'];$suitability=$proactive['suitability_assessment'];$proactiveSignature=$signature;}}}}
        return ['reply'=>$reply,'fact_updates'=>$factUpdates,'deterministic_ie'=>$deterministicAfter,'suitability_assessment'=>$suitability,'proactive_route_signature'=>$proactiveSignature,'proposed_knowledge'=>is_array($decoded['proposed_knowledge']??null)?$this->normaliseKnowledgeProposal($decoded['proposed_knowledge']):null,'confirm_pending_knowledge'=>(bool)($decoded['confirm_pending_knowledge']??false),'requested_action'=>$this->normaliseRequestedAction($decoded['requested_action']??null),'case_summary'=>trim((string)($decoded['case_summary']??'')),'action'=>is_array($decoded['action']??null)?$decoded['action']:null];
    }

    private function directActionResult(string $reply, bool $debtImportComplete = false): array {return ['reply'=>$reply,'debt_import_complete'=>$debtImportComplete,'requested_action'=>null,'fact_updates'=>[],'deterministic_ie'=>[],'suitability_assessment'=>null,'proactive_route_signature'=>null,'proposed_knowledge'=>null,'confirm_pending_knowledge'=>false,'case_summary'=>''];}
    private function acceptedIeOffer(array $history,string $message): bool {$answer=Str::lower(trim($message));$explicit=preg_match('/\b(carry out|run|start|calculate|complete)\b.*\bi\s*&\s*e\b/i',$message)===1;if($explicit)return true;if(!in_array($answer,['yes','y','yeah','yep','please','go ahead','do it'],true))return false;$last=collect($history)->reverse()->first(fn($item)=>($item['role']??null)==='assistant');return is_array($last)&&Str::contains(Str::lower((string)($last['content']??'')),['would you like me to carry out','carry out the zebra i&e','carry out the i&e']);}
    private function conciseIeCompletion(array $snapshot): string {$calc=$snapshot['calculation']??[];$income=(float)($calc['income_total']??0);$exp=(float)($calc['expenditure_total']??0);$di=(float)($calc['disposable_income']??0);$target=$calc['target_di']??null;$text='I&E complete. Income £'.number_format($income,0).', expenditure £'.number_format($exp,0).', DI £'.number_format($di,0).'.';if($target!==null){$target=(float)$target;$text.=' Target £'.number_format($target,0).($di>=$target?' achieved.':' not achieved — £'.number_format($target-$di,0).' short.');}return$text;}
    private function runProactiveComparison(string $apiKey,string $model,array $facts,array $routeAlert): ?array {$response=Http::timeout(60)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses',['model'=>$model,'instructions'=>'Perform a proactive IVA destination check only after a completed I&E has failed its target. Assess each destination independently. Keep the reply concise. Return only JSON with reply and suitability_assessment.','input'=>json_encode(['ROUTE_ALERT'=>$routeAlert,'ESTABLISHED_FACTS'=>$facts,'DESTINATION_COMPARISON'=>$this->destinationSuitability->context()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'max_output_tokens'=>1200]);if(!$response->successful())return null;$decoded=json_decode($this->stripCodeFence($this->extractOutputText($response->json())),true);if(!is_array($decoded)||!filled($decoded['reply']??null))return null;return ['reply'=>trim((string)$decoded['reply']),'suitability_assessment'=>$this->normaliseSuitabilityAssessment($decoded['suitability_assessment']??null)];}
    private function knowledgeForProfile(array $profile): Collection {$partner=Str::lower((string)($profile['partner']??''));$ip=Str::lower((string)($profile['ip']??''));return AssistantKnowledgeItem::query()->active()->orderByDesc('updated_at')->limit(250)->get(['id','scope','scope_key','category','title','content','updated_at'])->filter(function(AssistantKnowledgeItem $item)use($partner,$ip){$scope=Str::lower((string)$item->scope);$key=Str::lower(trim((string)$item->scope_key));if($scope==='company')return true;if($scope==='partner')return$partner!==''&&$key===$partner;if($scope==='ip')return$ip!==''&&$key===$ip;return false;});}
    private function establishedFacts(AssistantConversation $conversation): array {$facts=data_get($conversation->metadata,'established_facts',[]);$facts=is_array($facts)?$facts:[];$lead=$conversation->lead;if($lead){if($lead->monthly_housing_cost!==null&&!array_key_exists('housing.rent_mortgage',$facts))$facts['housing.rent_mortgage']=(float)$lead->monthly_housing_cost;if($lead->monthly_council_tax!==null&&!array_key_exists('housing.council_tax',$facts))$facts['housing.council_tax']=(float)$lead->monthly_council_tax;if($lead->estimated_total_debt!==null&&!array_key_exists('case.estimated_total_debt',$facts))$facts['case.estimated_total_debt']=(float)$lead->estimated_total_debt;if($lead->employment_status&&!array_key_exists('client.employment_status',$facts))$facts['client.employment_status']=$lead->employment_status;}return$facts;}
    private function leadContext(AssistantConversation $conversation): ?array {$lead=$conversation->lead;if(!$lead)return null;return ['id'=>$lead->id,'name'=>$lead->formattedName(),'wip_status'=>$lead->wip_status,'source'=>$lead->source,'employment_status'=>$lead->employment_status,'monthly_income'=>$lead->monthly_income,'monthly_housing_cost'=>$lead->monthly_housing_cost,'monthly_council_tax'=>$lead->monthly_council_tax,'monthly_utilities_cost'=>$lead->monthly_utilities_cost,'monthly_food_travel_cost'=>$lead->monthly_food_travel_cost,'estimated_total_debt'=>$lead->estimated_total_debt,'financial_statement'=>$lead->financial_statement];}
    private function findSimilarCases(AssistantConversation $conversation,string $message): array {$keywords=collect(preg_split('/[^a-zA-Z0-9]+/',Str::lower($message))?:[])->filter(fn($word)=>strlen($word)>=5)->reject(fn($word)=>in_array($word,['client','about','would','could','there','their','which','where','should'],true))->unique()->take(6)->values();if($keywords->isEmpty())return[];$query=AssistantMessage::query()->where('role','user')->where('conversation_id','!=',$conversation->id)->whereHas('conversation',fn($q)=>$q->whereNotNull('lead_id'));$query->where(function($q)use($keywords){foreach($keywords as$keyword)$q->orWhere('content','like','%'.$keyword.'%');});return$query->with('conversation:id,lead_id,summary')->latest('id')->limit(5)->get()->unique('conversation_id')->take(3)->map(fn(AssistantMessage $item)=>['lead_id'=>$item->conversation?->lead_id,'conversation_id'=>$item->conversation_id,'summary'=>$item->conversation?->summary,'matching_message_excerpt'=>Str::limit($item->content,350)])->values()->all();}
    private function normaliseRequestedAction(mixed $action): ?array {
        if(!is_array($action)||($action['type']??null)!=='schedule_callback')return null;
        $at=trim((string)($action['callback_at']??''));if($at==='')return null;
        return ['type'=>'schedule_callback','callback_at'=>Str::limit($at,40,''),'notes'=>Str::limit(trim((string)($action['notes']??'')),255,'')];
    }
    private function normaliseFactUpdates(mixed $updates): array {if(!is_array($updates))return[];$normalised=[];foreach(array_slice($updates,0,100,true)as$key=>$value){if(!is_string($key)||strlen($key)>120)continue;if(is_scalar($value)||$value===null)$normalised[$key]=$value;elseif(is_array($value)&&count($value)<=30)$normalised[$key]=array_values($value);}return$normalised;}
    private function normaliseSuitabilityAssessment(mixed $assessment): ?array {if(!is_array($assessment))return null;$allowed=['FIT','NOT_FIT','POSSIBLE_NEEDS_INFO','INSUFFICIENT_RULES'];$destinations=[];foreach(array_slice($assessment['destinations']??[],0,10)as$item){if(!is_array($item))continue;$status=strtoupper((string)($item['status']??''));if(!in_array($status,$allowed,true))$status='POSSIBLE_NEEDS_INFO';$destinations[]=['destination'=>Str::limit((string)($item['destination']??''),120,''),'status'=>$status,'reasons'=>collect($item['reasons']??[])->filter(fn($value)=>is_string($value))->take(10)->values()->all(),'missing'=>collect($item['missing']??[])->filter(fn($value)=>is_string($value))->take(10)->values()->all()];}return ['best_fit'=>filled($assessment['best_fit']??null)?Str::limit((string)$assessment['best_fit'],120,''):null,'best_fit_reason'=>Str::limit((string)($assessment['best_fit_reason']??''),1000,''),'destinations'=>$destinations];}
    private function normaliseKnowledgeProposal(array $proposal): array {$scope=in_array(($proposal['scope']??null),['company','partner','ip'],true)?$proposal['scope']:'company';return ['scope'=>$scope,'scope_key'=>filled($proposal['scope_key']??null)?Str::limit(trim((string)$proposal['scope_key']),120,''):null,'category'=>Str::limit((string)($proposal['category']??'General'),120,''),'title'=>Str::limit((string)($proposal['title']??'Updated rule'),255,''),'content'=>trim((string)($proposal['content']??''))];}
    private function extractOutputText(array $payload): string {if(is_string($payload['output_text']??null)&&$payload['output_text']!=='')return$payload['output_text'];foreach(($payload['output']??[])as$item)foreach(($item['content']??[])as$content)if(isset($content['text'])&&is_string($content['text']))return$content['text'];throw new RuntimeException('Assistant provider returned no text output.');}
    private function stripCodeFence(string $value): string {$value=trim($value);$value=preg_replace('/^```(?:json)?\s*/i','',$value)??$value;$value=preg_replace('/\s*```$/','',$value)??$value;return trim($value);}
}
