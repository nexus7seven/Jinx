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
    ) {}

    public function reply(AssistantConversation $conversation, string $message): array
    {
        $apiKey = (string) config('services.jinx_assistant.api_key');
        $model = (string) config('services.jinx_assistant.model');
        if ($apiKey === '' || $model === '') throw new RuntimeException('Jinx Assistant is not configured. Set JINX_ASSISTANT_API_KEY and JINX_ASSISTANT_MODEL.');

        $conversation->loadMissing('lead');
        $facts = $this->establishedFacts($conversation);
        $profile = $this->partnerKnowledge->profile($conversation->lead?->source, $facts);
        $knowledge = $this->knowledgeForProfile($profile);
        $history = $conversation->messages()->latest('id')->limit(24)->get()->reverse()->values()
            ->map(fn (AssistantMessage $item) => ['role'=>$item->role,'content'=>$item->content])->all();
        $similarCases = $this->findSimilarCases($conversation, $message);
        $pendingKnowledge = data_get($conversation->metadata, 'pending_knowledge');
        $deterministicBefore = $this->ieCalculator->snapshot($facts, $profile);
        $comparisonRequested = $this->destinationSuitability->shouldCompare($message);
        $destinationComparison = $comparisonRequested ? $this->destinationSuitability->context() : null;

        $instructions = <<<'PROMPT'
You are Jinx Assistant, an expert IVA case-packaging colleague used by trained case packagers inside the Jinx CRM.

The user is the case packager, not the IVA client. Be conversational and case-aware. Use information already known and ask only for genuinely missing facts.

KNOWLEDGE MODEL
Jinx uses scoped organisational knowledge. The current PARTNER_PROFILE identifies the partner and, where known, the IP destination.
Rule precedence is: IP-specific rule > partner-level rule > company-level rule. Never apply one partner's rule to another partner. Never apply one IP's criteria to another IP.

Current partner structure:
- Zebra: uses its own IP and Zebra codex/rules.
- Avondale: may submit to Lawson Fox, TIG, Assure or Anchorage Chambers. Each of those IPs can have distinct criteria. Avondale-wide rules apply to all four unless a more specific confirmed IP rule overrides them.

PARTNER_CODEX is the static authoritative baseline for the current partner. ACTIVE_SCOPED_KNOWLEDGE contains later confirmed organisational rules relevant to this exact case scope. A later confirmed scoped rule may supersede the static baseline when it clearly changes the same rule.
If a required partner/IP rule has not been supplied, do not invent it. State that the criterion is not yet in Jinx knowledge and ask for it only when needed.

CROSS-DESTINATION SUITABILITY
When DESTINATION_COMPARISON is present, assess the established case facts against every supplied destination independently. Use only that destination's codex plus its active scoped knowledge. Do not transfer a rule from one destination to another.
Statuses: FIT, NOT_FIT, POSSIBLE_NEEDS_INFO, INSUFFICIENT_RULES.
When asked where the case fits best, rank destinations by the cleanest confirmed fit. A FIT beats POSSIBLE_NEEDS_INFO. Never rank an INSUFFICIENT_RULES destination as the best fit. Explain decisive reasons, blockers and missing facts.

TRAINING / NEW RULES
When the packager supplies a durable new rule or changed criterion, identify its correct scope: company, partner, or ip. For Avondale, canonical IP names are Lawson Fox, TIG, Assure, Anchorage Chambers. If scope is ambiguous, ask. Do not save case-specific facts as organisational knowledge. Do not silently save a rule: return proposed_knowledge and ask for confirmation; on later clear confirmation set confirm_pending_knowledge=true.

FACT STORAGE
Every case fact supplied naturally in conversation should be returned in fact_updates using the stable key where one exists. This is how Jinx persists information back into CRM fields. Do not omit a clear fact merely because it was not asked formally.
For a new Zebra I&E, if calculation.target_di is not established, the first I&E question must be exactly: "What is the target DI?"
Maintain ESTABLISHED_FACTS. Every answer, including negative answers, becomes a fact. Never re-ask an established fact unless it changes, conflicts, or is genuinely insufficient.

For Avondale I&E, PARTNER_CODEX requires relevant SFS-controlled sections at 65% of applicable SFS maximum. Do not substitute Zebra-specific rules into Avondale.
DETERMINISTIC_IE is authoritative. For Zebra it applies supplied utility/SFS/transport rules and target-DI optimisation. For Avondale it applies the 65%-of-SFS-maximum rule. Never recalculate these figures differently. When enough information exists, use DETERMINISTIC_IE directly.

Return ONLY valid JSON:
{"reply":"natural-language reply","fact_updates":{},"suitability_assessment":null,"proposed_knowledge":null,"confirm_pending_knowledge":false,"case_summary":"brief rolling summary"}
If suitability_assessment is used it must contain best_fit, best_fit_reason and destinations with destination, status, reasons and missing. If proposed_knowledge is used it must contain scope, scope_key, category, title and content.
PROMPT;

        $context = [
            'CURRENT_LEAD'=>$this->leadContext($conversation),'PARTNER_PROFILE'=>$profile,'PARTNER_CODEX'=>$profile['partner_codex']??null,
            'ACTIVE_SCOPED_KNOWLEDGE'=>$knowledge->values()->toArray(),'DESTINATION_COMPARISON'=>$destinationComparison,
            'ESTABLISHED_FACTS'=>$facts,'DETERMINISTIC_IE'=>$deterministicBefore,'PENDING_KNOWLEDGE_PROPOSAL'=>$pendingKnowledge,
            'POTENTIALLY_SIMILAR_PRIOR_CASES'=>$similarCases,'CONVERSATION_HISTORY'=>$history,'LATEST_PACKAGER_MESSAGE'=>$message,
        ];
        $response = Http::timeout(60)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses', [
            'model'=>$model,'instructions'=>$instructions,'input'=>json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'max_output_tokens'=>$comparisonRequested?2600:1800,
        ]);
        if (!$response->successful()) throw new RuntimeException('Assistant provider error: '.$response->status().' '.$response->body());
        $decoded=json_decode($this->stripCodeFence($this->extractOutputText($response->json())),true);
        if (!is_array($decoded)||!isset($decoded['reply'])) throw new RuntimeException('Assistant returned an invalid response format.');

        $factUpdates=$this->normaliseFactUpdates($decoded['fact_updates']??[]);
        $factsAfter=array_replace($facts,$factUpdates);
        $profileAfter=$this->partnerKnowledge->profile($conversation->lead?->source,$factsAfter);
        $deterministicAfter=$this->ieCalculator->snapshot($factsAfter,$profileAfter);
        $factsForRouting=$factsAfter;
        if(isset($deterministicAfter['calculation']['disposable_income'])) $factsForRouting['calculation.disposable_income']=$deterministicAfter['calculation']['disposable_income'];
        if(isset($deterministicAfter['calculation']['income_total'])) $factsForRouting['income.total']=$deterministicAfter['calculation']['income_total'];
        $reply=trim((string)$decoded['reply']);
        $suitability=$this->normaliseSuitabilityAssessment($decoded['suitability_assessment']??null);
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
        return ['reply'=>$reply,'fact_updates'=>$factUpdates,'deterministic_ie'=>$deterministicAfter,'suitability_assessment'=>$suitability,
            'proactive_route_signature'=>$proactiveSignature,'proposed_knowledge'=>is_array($decoded['proposed_knowledge']??null)?$this->normaliseKnowledgeProposal($decoded['proposed_knowledge']):null,
            'confirm_pending_knowledge'=>(bool)($decoded['confirm_pending_knowledge']??false),'case_summary'=>trim((string)($decoded['case_summary']??''))];
    }

    private function runProactiveComparison(string $apiKey,string $model,array $facts,array $routeAlert): ?array
    {
        $instructions='You are Jinx Assistant performing a proactive IVA destination check. Assess each destination independently using supplied rules only. Return ONLY valid JSON with reply and suitability_assessment containing best_fit, best_fit_reason and destinations. Statuses: FIT, NOT_FIT, POSSIBLE_NEEDS_INFO, INSUFFICIENT_RULES.';
        $response=Http::timeout(60)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses',[
            'model'=>$model,'instructions'=>$instructions,'input'=>json_encode(['ROUTE_ALERT'=>$routeAlert,'ESTABLISHED_FACTS'=>$facts,'DESTINATION_COMPARISON'=>$this->destinationSuitability->context()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'max_output_tokens'=>2400]);
        if(!$response->successful()) return null;
        $decoded=json_decode($this->stripCodeFence($this->extractOutputText($response->json())),true);
        if(!is_array($decoded)||!filled($decoded['reply']??null)) return null;
        return ['reply'=>trim((string)$decoded['reply']),'suitability_assessment'=>$this->normaliseSuitabilityAssessment($decoded['suitability_assessment']??null)];
    }

    private function knowledgeForProfile(array $profile): Collection
    {
        $partner=Str::lower((string)($profile['partner']??''));$ip=Str::lower((string)($profile['ip']??''));
        return AssistantKnowledgeItem::query()->active()->orderByDesc('updated_at')->limit(250)->get(['id','scope','scope_key','category','title','content','updated_at'])
            ->filter(function(AssistantKnowledgeItem $item)use($partner,$ip){$scope=Str::lower((string)$item->scope);$key=Str::lower(trim((string)$item->scope_key));if($scope==='company')return true;if($scope==='partner')return $partner!==''&&$key===$partner;if($scope==='ip')return $ip!==''&&$key===$ip;return false;});
    }

    private function establishedFacts(AssistantConversation $conversation): array
    {
        $facts=data_get($conversation->metadata,'established_facts',[]);$facts=is_array($facts)?$facts:[];$lead=$conversation->lead;
        if($lead){if($lead->monthly_housing_cost!==null&&!array_key_exists('housing.rent_mortgage',$facts))$facts['housing.rent_mortgage']=(float)$lead->monthly_housing_cost;if($lead->monthly_council_tax!==null&&!array_key_exists('housing.council_tax',$facts))$facts['housing.council_tax']=(float)$lead->monthly_council_tax;if($lead->estimated_total_debt!==null&&!array_key_exists('case.estimated_total_debt',$facts))$facts['case.estimated_total_debt']=(float)$lead->estimated_total_debt;if($lead->employment_status&&!array_key_exists('client.employment_status',$facts))$facts['client.employment_status']=$lead->employment_status;}
        return $facts;
    }

    private function leadContext(AssistantConversation $conversation): ?array
    {
        $lead=$conversation->lead;if(!$lead)return null;return ['id'=>$lead->id,'name'=>$lead->formattedName(),'wip_status'=>$lead->wip_status,'source'=>$lead->source,'employment_status'=>$lead->employment_status,'monthly_income'=>$lead->monthly_income,'monthly_housing_cost'=>$lead->monthly_housing_cost,'monthly_council_tax'=>$lead->monthly_council_tax,'monthly_utilities_cost'=>$lead->monthly_utilities_cost,'monthly_food_travel_cost'=>$lead->monthly_food_travel_cost,'estimated_total_debt'=>$lead->estimated_total_debt,'financial_statement'=>$lead->financial_statement];
    }

    private function findSimilarCases(AssistantConversation $conversation,string $message): array
    {
        $keywords=collect(preg_split('/[^a-zA-Z0-9]+/',Str::lower($message))?:[])->filter(fn($word)=>strlen($word)>=5)->reject(fn($word)=>in_array($word,['client','about','would','could','there','their','which','where','should'],true))->unique()->take(6)->values();if($keywords->isEmpty())return[];
        $query=AssistantMessage::query()->where('role','user')->where('conversation_id','!=',$conversation->id)->whereHas('conversation',fn($q)=>$q->whereNotNull('lead_id'));$query->where(function($q)use($keywords){foreach($keywords as $keyword)$q->orWhere('content','like','%'.$keyword.'%');});
        return $query->with('conversation:id,lead_id,summary')->latest('id')->limit(5)->get()->unique('conversation_id')->take(3)->map(fn(AssistantMessage $item)=>['lead_id'=>$item->conversation?->lead_id,'conversation_id'=>$item->conversation_id,'summary'=>$item->conversation?->summary,'matching_message_excerpt'=>Str::limit($item->content,350)])->values()->all();
    }

    private function normaliseFactUpdates(mixed $updates): array
    {
        if(!is_array($updates))return[];$normalised=[];foreach(array_slice($updates,0,100,true)as$key=>$value){if(!is_string($key)||strlen($key)>120)continue;if(is_scalar($value)||$value===null)$normalised[$key]=$value;elseif(is_array($value)&&count($value)<=30)$normalised[$key]=array_values($value);}return$normalised;
    }

    private function normaliseSuitabilityAssessment(mixed $assessment): ?array
    {
        if(!is_array($assessment))return null;$allowed=['FIT','NOT_FIT','POSSIBLE_NEEDS_INFO','INSUFFICIENT_RULES'];$destinations=[];
        foreach(array_slice($assessment['destinations']??[],0,10)as$item){if(!is_array($item))continue;$status=strtoupper((string)($item['status']??''));if(!in_array($status,$allowed,true))$status='POSSIBLE_NEEDS_INFO';$destinations[]=['destination'=>Str::limit((string)($item['destination']??''),120,''),'status'=>$status,'reasons'=>collect($item['reasons']??[])->filter(fn($value)=>is_string($value))->take(10)->values()->all(),'missing'=>collect($item['missing']??[])->filter(fn($value)=>is_string($value))->take(10)->values()->all()];}
        return ['best_fit'=>filled($assessment['best_fit']??null)?Str::limit((string)$assessment['best_fit'],120,''):null,'best_fit_reason'=>Str::limit((string)($assessment['best_fit_reason']??''),1000,''),'destinations'=>$destinations];
    }

    private function normaliseKnowledgeProposal(array $proposal): array
    {
        $scope=in_array(($proposal['scope']??null),['company','partner','ip'],true)?$proposal['scope']:'company';return ['scope'=>$scope,'scope_key'=>filled($proposal['scope_key']??null)?Str::limit(trim((string)$proposal['scope_key']),120,''):null,'category'=>Str::limit((string)($proposal['category']??'General'),120,''),'title'=>Str::limit((string)($proposal['title']??'Updated rule'),255,''),'content'=>trim((string)($proposal['content']??''))];
    }

    private function extractOutputText(array $payload): string
    {
        if(is_string($payload['output_text']??null)&&$payload['output_text']!=='')return$payload['output_text'];foreach(($payload['output']??[])as$item)foreach(($item['content']??[])as$content)if(isset($content['text'])&&is_string($content['text']))return$content['text'];throw new RuntimeException('Assistant provider returned no text output.');
    }

    private function stripCodeFence(string $value): string
    {
        $value=trim($value);$value=preg_replace('/^```(?:json)?\s*/i','',$value)??$value;$value=preg_replace('/\s*```$/','',$value)??$value;return trim($value);
    }
}
