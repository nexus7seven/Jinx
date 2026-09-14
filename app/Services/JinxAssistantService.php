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
    public function __construct(private readonly PartnerKnowledgeService $partnerKnowledge,private readonly DestinationSuitabilityService $destinationSuitability,private readonly ProactiveRoutingService $proactiveRouting,private readonly AssistantIeCalculationService $ieCalculator) {}

    public function reply(AssistantConversation $conversation,string $message): array
    {
        $apiKey=(string)config('services.jinx_assistant.api_key');$model=(string)config('services.jinx_assistant.model');
        if($apiKey===''||$model==='')throw new RuntimeException('Jinx Assistant is not configured. Set JINX_ASSISTANT_API_KEY and JINX_ASSISTANT_MODEL.');

        $conversation->loadMissing('lead');
        $facts=$this->establishedFacts($conversation);
        $profile=$this->partnerKnowledge->profile($conversation->lead?->source,$facts);
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

TRAINING / NEW RULES
Durable rules must be proposed at company, partner or IP scope and confirmed before saving. Do not save case facts as organisational knowledge.

Return ONLY valid JSON:
{"reply":"natural-language reply","fact_updates":{},"suitability_assessment":null,"proposed_knowledge":null,"confirm_pending_knowledge":false,"case_summary":"brief rolling summary"}
PROMPT;

        $context=['CURRENT_LEAD'=>$this->leadContext($conversation),'PARTNER_PROFILE'=>$profile,'PARTNER_CODEX'=>$profile['partner_codex']??null,'ACTIVE_SCOPED_KNOWLEDGE'=>$knowledge->values()->toArray(),'DESTINATION_COMPARISON'=>$destinationComparison,'ESTABLISHED_FACTS'=>$facts,'DETERMINISTIC_IE'=>$deterministicBefore,'PENDING_KNOWLEDGE_PROPOSAL'=>$pendingKnowledge,'POTENTIALLY_SIMILAR_PRIOR_CASES'=>$similarCases,'CONVERSATION_HISTORY'=>$history,'LATEST_PACKAGER_MESSAGE'=>$message];
        $response=Http::timeout(60)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses',['model'=>$model,'instructions'=>$instructions,'input'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'max_output_tokens'=>$comparisonRequested?2600:1800]);
        if(!$response->successful())throw new RuntimeException('Assistant provider error: '.$response->status().' '.$response->body());
        $decoded=json_decode($this->stripCodeFence($this->extractOutputText($response->json())),true);
        if(!is_array($decoded)||!isset($decoded['reply']))throw new RuntimeException('Assistant returned an invalid response format.');

        $factUpdates=$this->normaliseFactUpdates($decoded['fact_updates']??[]);

        // Starting an I&E is a workflow decision, not something left to model discretion.
        if(!(($facts['workflow.ie_active']??false)===true) && $this->acceptedIeOffer($history,$message)) {
            $factUpdates['workflow.ie_active']=true;
        }

        $factsAfter=array_replace($facts,$factUpdates);
        $profileAfter=$this->partnerKnowledge->profile($conversation->lead?->source,$factsAfter);
        $reply=trim((string)$decoded['reply']);
        $suitability=$this->normaliseSuitabilityAssessment($decoded['suitability_assessment']??null);

        // The Zebra interview is deterministic. The markdown defines what must be
        // established; the model extracts answers, but it does not decide to skip steps.
        if(($profileAfter['partner']??null)==='Zebra' && (($factsAfter['workflow.ie_active']??false)===true)) {
            $next=$this->nextZebraIeQuestion($factsAfter);
            $incomeComplete=$this->zebraIncomeComplete($factsAfter);
            $factUpdates['workflow.income_complete']=$incomeComplete;
            $factsAfter['workflow.income_complete']=$incomeComplete;

            if($next!==null) {
                $factUpdates['workflow.ie_complete']=false;
                $factsAfter['workflow.ie_complete']=false;
                $reply=$next;
                $suitability=null;
            } else {
                $factUpdates['workflow.ie_complete']=true;
                $factsAfter['workflow.ie_complete']=true;
            }
        }

        $deterministicAfter=$this->ieCalculator->snapshot($factsAfter,$profileAfter);

        // Completed-I&E replies should be concise because the full detail is already visible
        // in the Financial Statement. Routing is allowed only after the interview is complete.
        if(($profileAfter['partner']??null)==='Zebra' && (($factsAfter['workflow.ie_complete']??false)===true)) {
            $reply=$this->conciseIeCompletion($deterministicAfter);
            $suitability=null;
        }

        $factsForRouting=$factsAfter;
        if(($factsAfter['workflow.ie_complete']??false)===true){
            $factsForRouting['calculation.disposable_income']=$deterministicAfter['calculation']['disposable_income']??null;
            $factsForRouting['calculation.target_di']=$deterministicAfter['calculation']['target_di']??null;
            $factsForRouting['income.total']=$deterministicAfter['calculation']['income_total']??null;
        }

        $proactiveSignature=null;
        if(!$comparisonRequested){
            $routeAlert=$this->proactiveRouting->evaluate($profileAfter,$factsForRouting,$conversation->lead);
            if($routeAlert){
                $signature=sha1(json_encode($routeAlert,JSON_UNESCAPED_SLASHES));
                if($signature!==(string)data_get($conversation->metadata,'last_proactive_route_signature','')){
                    $proactive=$this->runProactiveComparison($apiKey,$model,$factsForRouting,$routeAlert);
                    if($proactive){$reply.="\n\n".$proactive['reply'];$suitability=$proactive['suitability_assessment'];$proactiveSignature=$signature;}
                }
            }
        }

        return ['reply'=>$reply,'fact_updates'=>$factUpdates,'deterministic_ie'=>$deterministicAfter,'suitability_assessment'=>$suitability,'proactive_route_signature'=>$proactiveSignature,'proposed_knowledge'=>is_array($decoded['proposed_knowledge']??null)?$this->normaliseKnowledgeProposal($decoded['proposed_knowledge']):null,'confirm_pending_knowledge'=>(bool)($decoded['confirm_pending_knowledge']??false),'case_summary'=>trim((string)($decoded['case_summary']??''))];
    }

    private function acceptedIeOffer(array $history,string $message): bool
    {
        $answer=Str::lower(trim($message));
        $explicit=preg_match('/\b(carry out|run|start|calculate|complete)\b.*\bi\s*&\s*e\b/i',$message)===1;
        if($explicit)return true;
        if(!in_array($answer,['yes','y','yeah','yep','please','go ahead','do it'],true))return false;
        $last=collect($history)->reverse()->first(fn($item)=>($item['role']??null)==='assistant');
        return is_array($last) && Str::contains(Str::lower((string)($last['content']??'')),['would you like me to carry out','carry out the zebra i&e','carry out the i&e']);
    }

    private function nextZebraIeQuestion(array $facts): ?string
    {
        if(!$this->hasFact($facts,'calculation.target_di'))return 'What is the target DI?';
        if(!$this->hasFact($facts,'income.client_salary'))return "What is the client's monthly take-home salary?";
        if(!$this->hasFact($facts,'household.partner_exists'))return 'Does the client have a partner?';
        if($this->factBool($facts,'household.partner_exists')===true && !$this->hasFact($facts,'income.partner_salary'))return "What is the partner's monthly take-home salary?";
        if(!$this->hasFact($facts,'household.children_count'))return 'How many children live with the client?';
        $children=(int)($facts['household.children_count']??0);
        if($children>0){$ages=$facts['household.children_ages']??[];if(!is_array($ages)||count($ages)!==$children)return "What are the ages of the {$children} children living with the client?";}

        // UC is intentionally its own checkpoint and must be resolved before the grouped screen.
        if(!$this->hasFact($facts,'income.universal_credit'))return "What is the client's monthly Universal Credit? Enter 0 if none.";

        if(!$this->secondaryIncomeScreenComplete($facts))return "Does the client or their partner receive any of the following? If yes, state which and the monthly amount: PIP/DLA, ESA, Carer's Allowance, maintenance income, pension income, student loan/grant/bursary, or Foster/Guardianship Allowance. If none, enter 0.";

        if(!$this->hasFact($facts,'housing.rent_mortgage'))return 'What is the monthly rent or mortgage?';
        if(!$this->hasFact($facts,'housing.council_tax'))return 'What is the monthly Council Tax?';
        if(!$this->hasFact($facts,'transport.client.mode'))return 'Does the client have a car or use public transport?';
        if($this->isCarMode($facts['transport.client.mode']??null) && !$this->hasFact($facts,'transport.client.car_insurance'))return "What is the client's monthly car insurance?";

        if($this->factBool($facts,'household.partner_exists')===true){
            if(!$this->hasFact($facts,'transport.partner.mode'))return 'Does the partner have a car or use public transport?';
            if($this->isCarMode($facts['transport.partner.mode']??null) && !$this->hasFact($facts,'transport.partner.car_insurance'))return "What is the partner's monthly car insurance?";
        }

        if(!$this->hasFact($facts,'other.childcare'))return 'Does the client have any monthly childcare costs? Enter 0 if none.';
        if(!$this->hasFact($facts,'other.maintenance_paid'))return 'Does the client pay monthly maintenance for children who do not live with them? Enter 0 if none.';
        return null;
    }

    private function zebraIncomeComplete(array $facts): bool
    {
        if(!$this->hasFact($facts,'income.client_salary'))return false;
        if(!$this->hasFact($facts,'household.partner_exists'))return false;
        if($this->factBool($facts,'household.partner_exists')===true && !$this->hasFact($facts,'income.partner_salary'))return false;
        if(!$this->hasFact($facts,'household.children_count'))return false;
        $children=(int)($facts['household.children_count']??0);
        if($children>0 && (!isset($facts['household.children_ages'])||!is_array($facts['household.children_ages'])||count($facts['household.children_ages'])!==$children))return false;
        if(!$this->hasFact($facts,'income.universal_credit'))return false;
        return $this->secondaryIncomeScreenComplete($facts);
    }

    private function secondaryIncomeScreenComplete(array $facts): bool
    {
        foreach(['income.pip_dla','income.esa','income.carers_allowance','income.maintenance_received','income.pension','income.student','income.foster_guardianship'] as $key){
            if(!$this->hasFact($facts,$key))return false;
        }
        return true;
    }

    private function conciseIeCompletion(array $snapshot): string
    {
        $calc=$snapshot['calculation']??[];
        $income=(float)($calc['income_total']??0);$exp=(float)($calc['expenditure_total']??0);$di=(float)($calc['disposable_income']??0);$target=$calc['target_di']??null;
        $text='I&E complete. Income £'.number_format($income,0).', expenditure £'.number_format($exp,0).', DI £'.number_format($di,0).'.';
        if($target!==null){$target=(float)$target;$text.=' Target £'.number_format($target,0).($di>=$target?' achieved.':' not achieved — £'.number_format($target-$di,0).' short.');}
        return $text;
    }

    private function hasFact(array $facts,string $key): bool { return array_key_exists($key,$facts) && $facts[$key]!==null && $facts[$key]!==''; }
    private function factBool(array $facts,string $key): ?bool { return $this->hasFact($facts,$key)?filter_var($facts[$key],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE):null; }
    private function isCarMode(mixed $value): bool { return in_array(Str::lower(trim((string)$value)),['car','vehicle'],true); }

    private function runProactiveComparison(string $apiKey,string $model,array $facts,array $routeAlert): ?array {$response=Http::timeout(60)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses',['model'=>$model,'instructions'=>'Perform a proactive IVA destination check only after a completed I&E has failed its target. Assess each destination independently. Keep the reply concise. Return only JSON with reply and suitability_assessment.','input'=>json_encode(['ROUTE_ALERT'=>$routeAlert,'ESTABLISHED_FACTS'=>$facts,'DESTINATION_COMPARISON'=>$this->destinationSuitability->context()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'max_output_tokens'=>1200]);if(!$response->successful())return null;$decoded=json_decode($this->stripCodeFence($this->extractOutputText($response->json())),true);if(!is_array($decoded)||!filled($decoded['reply']??null))return null;return ['reply'=>trim((string)$decoded['reply']),'suitability_assessment'=>$this->normaliseSuitabilityAssessment($decoded['suitability_assessment']??null)];}
    private function knowledgeForProfile(array $profile): Collection {$partner=Str::lower((string)($profile['partner']??''));$ip=Str::lower((string)($profile['ip']??''));return AssistantKnowledgeItem::query()->active()->orderByDesc('updated_at')->limit(250)->get(['id','scope','scope_key','category','title','content','updated_at'])->filter(function(AssistantKnowledgeItem $item)use($partner,$ip){$scope=Str::lower((string)$item->scope);$key=Str::lower(trim((string)$item->scope_key));if($scope==='company')return true;if($scope==='partner')return$partner!==''&&$key===$partner;if($scope==='ip')return$ip!==''&&$key===$ip;return false;});}
    private function establishedFacts(AssistantConversation $conversation): array {$facts=data_get($conversation->metadata,'established_facts',[]);$facts=is_array($facts)?$facts:[];$lead=$conversation->lead;if($lead){if($lead->monthly_housing_cost!==null&&!array_key_exists('housing.rent_mortgage',$facts))$facts['housing.rent_mortgage']=(float)$lead->monthly_housing_cost;if($lead->monthly_council_tax!==null&&!array_key_exists('housing.council_tax',$facts))$facts['housing.council_tax']=(float)$lead->monthly_council_tax;if($lead->estimated_total_debt!==null&&!array_key_exists('case.estimated_total_debt',$facts))$facts['case.estimated_total_debt']=(float)$lead->estimated_total_debt;if($lead->employment_status&&!array_key_exists('client.employment_status',$facts))$facts['client.employment_status']=$lead->employment_status;}return$facts;}
    private function leadContext(AssistantConversation $conversation): ?array {$lead=$conversation->lead;if(!$lead)return null;return ['id'=>$lead->id,'name'=>$lead->formattedName(),'wip_status'=>$lead->wip_status,'source'=>$lead->source,'employment_status'=>$lead->employment_status,'monthly_income'=>$lead->monthly_income,'monthly_housing_cost'=>$lead->monthly_housing_cost,'monthly_council_tax'=>$lead->monthly_council_tax,'monthly_utilities_cost'=>$lead->monthly_utilities_cost,'monthly_food_travel_cost'=>$lead->monthly_food_travel_cost,'estimated_total_debt'=>$lead->estimated_total_debt,'financial_statement'=>$lead->financial_statement];}
    private function findSimilarCases(AssistantConversation $conversation,string $message): array {$keywords=collect(preg_split('/[^a-zA-Z0-9]+/',Str::lower($message))?:[])->filter(fn($word)=>strlen($word)>=5)->reject(fn($word)=>in_array($word,['client','about','would','could','there','their','which','where','should'],true))->unique()->take(6)->values();if($keywords->isEmpty())return[];$query=AssistantMessage::query()->where('role','user')->where('conversation_id','!=',$conversation->id)->whereHas('conversation',fn($q)=>$q->whereNotNull('lead_id'));$query->where(function($q)use($keywords){foreach($keywords as$keyword)$q->orWhere('content','like','%'.$keyword.'%');});return$query->with('conversation:id,lead_id,summary')->latest('id')->limit(5)->get()->unique('conversation_id')->take(3)->map(fn(AssistantMessage $item)=>['lead_id'=>$item->conversation?->lead_id,'conversation_id'=>$item->conversation_id,'summary'=>$item->conversation?->summary,'matching_message_excerpt'=>Str::limit($item->content,350)])->values()->all();}
    private function normaliseFactUpdates(mixed $updates): array {if(!is_array($updates))return[];$normalised=[];foreach(array_slice($updates,0,100,true)as$key=>$value){if(!is_string($key)||strlen($key)>120)continue;if(is_scalar($value)||$value===null)$normalised[$key]=$value;elseif(is_array($value)&&count($value)<=30)$normalised[$key]=array_values($value);}return$normalised;}
    private function normaliseSuitabilityAssessment(mixed $assessment): ?array {if(!is_array($assessment))return null;$allowed=['FIT','NOT_FIT','POSSIBLE_NEEDS_INFO','INSUFFICIENT_RULES'];$destinations=[];foreach(array_slice($assessment['destinations']??[],0,10)as$item){if(!is_array($item))continue;$status=strtoupper((string)($item['status']??''));if(!in_array($status,$allowed,true))$status='POSSIBLE_NEEDS_INFO';$destinations[]=['destination'=>Str::limit((string)($item['destination']??''),120,''),'status'=>$status,'reasons'=>collect($item['reasons']??[])->filter(fn($value)=>is_string($value))->take(10)->values()->all(),'missing'=>collect($item['missing']??[])->filter(fn($value)=>is_string($value))->take(10)->values()->all()];}return ['best_fit'=>filled($assessment['best_fit']??null)?Str::limit((string)$assessment['best_fit'],120,''):null,'best_fit_reason'=>Str::limit((string)($assessment['best_fit_reason']??''),1000,''),'destinations'=>$destinations];}
    private function normaliseKnowledgeProposal(array $proposal): array {$scope=in_array(($proposal['scope']??null),['company','partner','ip'],true)?$proposal['scope']:'company';return ['scope'=>$scope,'scope_key'=>filled($proposal['scope_key']??null)?Str::limit(trim((string)$proposal['scope_key']),120,''):null,'category'=>Str::limit((string)($proposal['category']??'General'),120,''),'title'=>Str::limit((string)($proposal['title']??'Updated rule'),255,''),'content'=>trim((string)($proposal['content']??''))];}
    private function extractOutputText(array $payload): string {if(is_string($payload['output_text']??null)&&$payload['output_text']!=='')return$payload['output_text'];foreach(($payload['output']??[])as$item)foreach(($item['content']??[])as$content)if(isset($content['text'])&&is_string($content['text']))return$content['text'];throw new RuntimeException('Assistant provider returned no text output.');}
    private function stripCodeFence(string $value): string {$value=trim($value);$value=preg_replace('/^```(?:json)?\s*/i','',$value)??$value;$value=preg_replace('/\s*```$/','',$value)??$value;return trim($value);}
}
