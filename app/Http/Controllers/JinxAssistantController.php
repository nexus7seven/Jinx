<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Models\Lead;
use App\Services\AssistantLeadFactSyncService;
use App\Services\DecisionCaseFactService;
use App\Services\DecisionFactRegistryService;
use App\Services\IvaCasePackagingPlannerService;
use App\Services\JinxAgentService;
use App\Services\JinxAssistantService;
use App\Services\VicidialCallbackService;
use App\Services\ZebraIeInterviewAnswerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Throwable;

class JinxAssistantController extends Controller
{
    public function bootstrap(Request $request, Lead $lead, VicidialCallbackService $callbacks): JsonResponse
    {
        $conversation = $this->conversationFor($request, $lead);
        $callback = collect($callbacks->activeForJinxLeads())->firstWhere('lead_id', $lead->id);
        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'active_callback' => $callback,
            'knowledge_count' => AssistantKnowledgeItem::active()->count(),
            'pending_knowledge' => data_get($conversation->metadata, 'pending_knowledge'),
            'established_facts' => data_get($conversation->metadata, 'established_facts', []),
            'last_suitability_assessment' => data_get($conversation->metadata, 'last_suitability_assessment'),
            'messages' => $conversation->messages()->latest('id')->limit(60)->get()->reverse()->values()->map(fn (AssistantMessage $message) => [
                'id' => $message->id, 'role' => $message->role, 'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function send(
        Request $request,
        Lead $lead,
        JinxAssistantService $assistant,
        JinxAgentService $agent,
        AssistantLeadFactSyncService $factSync,
        ZebraIeInterviewAnswerService $zebraAnswers,
        VicidialCallbackService $callbacks,
        IvaCasePackagingPlannerService $packagingPlanner,
        DecisionCaseFactService $decisionFacts,
        DecisionFactRegistryService $factRegistry,
    ): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:12000']]);
        $messageText = trim($validated['message']);
        $conversation = $this->conversationFor($request, $lead);
        $userMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user', 'content' => $messageText,
        ]);

        try {
            $metadata = $conversation->metadata ?? [];
            $existingFacts = data_get($metadata, 'established_facts', []);
            $existingFacts = is_array($existingFacts) ? $existingFacts : [];
            $ieCompleteBefore = ($existingFacts['workflow.ie_complete'] ?? false) === true;

            $pendingDecisionQuestion = data_get($metadata, 'pending_decision_question');
            if ($ieCompleteBefore && is_array($pendingDecisionQuestion) && !$this->isIeStartRequest($messageText)) {
                $parsed = $packagingPlanner->parseAnswer($pendingDecisionQuestion, $messageText);
                if (($parsed['valid'] ?? false) !== true) {
                    return $this->directAssistantReply(
                        $conversation,
                        $packagingPlanner->invalidAnswerMessage($pendingDecisionQuestion),
                        $existingFacts,
                        [],
                        ['decision_question' => $pendingDecisionQuestion]
                    );
                }

                if (($pendingDecisionQuestion['scope'] ?? 'case') === 'debt') {
                    $debtId = (int) ($pendingDecisionQuestion['debt_id'] ?? 0);
                    $debt = $lead->debts()->findOrFail($debtId);
                    $decisionFacts->setDebtFact(
                        $debt,
                        (string) $pendingDecisionQuestion['fact_key'],
                        $parsed['value'],
                        'operator',
                        'Captured by Jinx case assistant'
                    );
                } else {
                    $decisionFacts->setLeadFact(
                        $lead->fresh(),
                        (string) $pendingDecisionQuestion['fact_key'],
                        $parsed['value'],
                        'operator',
                        'Captured by Jinx case assistant'
                    );
                }
                unset($metadata['pending_decision_question']);
                $conversation->metadata = $metadata;
                $conversation->save();

                $plan = $packagingPlanner->plan($lead->fresh());
                if (($plan['state'] ?? null) === 'needs_fact' && is_array($plan['question'] ?? null)) {
                    $metadata = $conversation->fresh()->metadata ?? [];
                    $metadata['pending_decision_question'] = $plan['question'];
                    $conversation->metadata = $metadata;
                    $conversation->save();

                    return $this->directAssistantReply(
                        $conversation,
                        'Got it. '.$plan['message'],
                        $existingFacts,
                        [],
                        [
                            'decision_fact_changed' => true,
                            'decision_plan_state' => 'needs_fact',
                            'decision_question' => $plan['question'],
                        ]
                    );
                }

                if (($plan['state'] ?? null) === 'needs_debts') {
                    return $this->directAssistantReply(
                        $conversation,
                        (string) $plan['message'],
                        $existingFacts,
                        [],
                        ['decision_fact_changed' => true, 'decision_plan_state' => 'needs_debts']
                    );
                }

                return $this->agentAssistantReply(
                    $conversation,
                    $agent,
                    $messageText,
                    $this->packagingDirective($lead),
                    ['decision_fact_changed' => true, 'decision_plan_state' => 'ready_for_agent'],
                    'case'
                );
            }

            if ($ieCompleteBefore && !$this->isIeStartRequest($messageText)) {
                if ($this->isPackagingRequest($messageText) || $this->isContinueCaseRequest($messageText)) {
                    $plan = $packagingPlanner->plan($lead->fresh());
                    if (($plan['state'] ?? null) === 'needs_fact' && is_array($plan['question'] ?? null)) {
                        $metadata['pending_decision_question'] = $plan['question'];
                        $conversation->metadata = $metadata;
                        $conversation->save();

                        return $this->directAssistantReply(
                            $conversation,
                            'I’ve checked what is already recorded on the case. Before I run the full reasoning, '.$this->lowercaseFirst((string) $plan['message']),
                            $existingFacts,
                            [],
                            ['decision_plan_state' => 'needs_fact', 'decision_question' => $plan['question']]
                        );
                    }
                    if (($plan['state'] ?? null) === 'needs_debts') {
                        return $this->directAssistantReply(
                            $conversation,
                            (string) $plan['message'],
                            $existingFacts,
                            [],
                            ['decision_plan_state' => 'needs_debts']
                        );
                    }

                    return $this->agentAssistantReply(
                        $conversation,
                        $agent,
                        $messageText,
                        $this->packagingDirective($lead),
                        ['decision_plan_state' => 'ready_for_agent'],
                        'case'
                    );
                }

                return $this->agentAssistantReply($conversation, $agent, $messageText);
            }

            if (($metadata['pending_ie_reset_confirmation'] ?? false) === true) {
                $answer = strtolower(trim($messageText));
                if (in_array($answer, ['yes','y','yeah','yep','reset','start fresh','fresh'], true)) {
                    $syncedFields = $factSync->resetIe($lead->fresh());
                    $cleanFacts = $this->withoutIeFacts($existingFacts);
                    $startFacts = $zebraAnswers->start($cleanFacts);
                    $metadata['established_facts'] = array_replace($cleanFacts, $startFacts);
                    unset($metadata['pending_ie_reset_confirmation'], $metadata['pending_decision_question'], $metadata['deterministic_ie'], $metadata['last_suitability_assessment'], $metadata['last_proactive_route_signature']);
                    $conversation->metadata = $metadata;
                    $conversation->save();
                    return $this->directAssistantReply($conversation, 'Financial Statement cleared. '.$zebraAnswers->nextQuestion($metadata['established_facts']), $metadata['established_facts'], $syncedFields);
                }
                if (in_array($answer, ['no','n','nope','keep it','keep','use existing'], true)) {
                    unset($metadata['pending_ie_reset_confirmation'], $metadata['pending_decision_question']);
                    $startFacts = $zebraAnswers->start($existingFacts);
                    $metadata['established_facts'] = array_replace($existingFacts, $startFacts);
                    $conversation->metadata = $metadata;
                    $conversation->save();
                    $next = $zebraAnswers->nextQuestion($metadata['established_facts']);
                    $reply = $next !== null ? 'Okay, I’ll keep the existing Financial Statement. '.$next : 'Okay, I’ll keep the existing Financial Statement and use the recorded I&E figures.';
                    return $this->directAssistantReply($conversation, $reply, $metadata['established_facts']);
                }
                return $this->directAssistantReply($conversation, 'The Financial Statement already contains I&E data. Do you want me to reset the complete Financial Statement and start fresh? Yes or no.', $existingFacts);
            }

            if ($this->isIeStartRequest($messageText) && $factSync->hasPopulatedIe($lead->fresh())) {
                $metadata['pending_ie_reset_confirmation'] = true;
                $conversation->metadata = $metadata;
                $conversation->save();
                return $this->directAssistantReply($conversation, 'The Financial Statement already contains I&E data. Do you want me to reset the complete Financial Statement and start fresh? Yes or no.', $existingFacts);
            }

            $startFacts = $this->isIeStartRequest($messageText) ? $zebraAnswers->start($existingFacts) : [];
            $householdFacts = $this->explicitHouseholdFacts($messageText);
            $factsForAnswer = array_replace($existingFacts, $startFacts, $householdFacts);

            if (($factsForAnswer['workflow.ie_active'] ?? false) === true && $householdFacts !== []) {
                $factsForAnswer = array_replace($factsForAnswer, $zebraAnswers->start(array_replace($existingFacts, $householdFacts)), [
                    'workflow.ie_active' => true,
                    'workflow.income_complete' => $startFacts['workflow.income_complete'] ?? ($existingFacts['workflow.income_complete'] ?? false),
                    'workflow.ie_complete' => false,
                ]);
            }

            $answerFacts = $zebraAnswers->extract($lead, $factsForAnswer, $messageText);
            $preFacts = array_replace($startFacts, $householdFacts, $answerFacts);

            $syncedFields = [];
            if ($preFacts !== []) {
                $metadata['established_facts'] = array_replace($existingFacts, $preFacts);
                $conversation->metadata = $metadata;
                $conversation->save();
                $syncedFields = array_merge($syncedFields, $factSync->sync($lead->fresh(), $preFacts));
            }

            // The controller has already parsed and persisted the exact current checkpoint.
            // Never let the assistant service parse the same raw answer again against the NEXT
            // checkpoint (e.g. target DI 110 becoming salary 110, or rent 600 becoming council tax 600).
            $activeFacts = data_get($conversation->fresh()->metadata, 'established_facts', []);
            $assistantInput = (($activeFacts['workflow.ie_active'] ?? false) === true && $answerFacts !== [])
                ? '[Current I&E checkpoint answer already persisted deterministically. Advance to the next unresolved checkpoint.]'
                : $userMessage->content;
            $result = $assistant->reply($conversation->fresh(), $assistantInput);

            if (($result['requested_action']['type'] ?? null) === 'schedule_callback') {
                try {
                    $when = Carbon::parse((string) $result['requested_action']['callback_at']);
                    if ($when->isPast()) throw new \RuntimeException('Callback time must be in the future.');
                    $scheduled = $callbacks->schedule($lead->fresh(), $when, (string) ($result['requested_action']['notes'] ?? ''));
                    $result['reply'] = 'Callback booked for '.$when->format('D j M \a\t H:i').'.';
                    if (($scheduled['comments'] ?? '') !== '') $result['reply'] .= ' Note: '.$scheduled['comments'];
                    $syncedFields[] = 'vicidial_callback';
                } catch (Throwable $actionError) {
                    report($actionError);
                    $result['reply'] = 'I understood the callback request, but it was not booked because the VICIdial write failed: '.$actionError->getMessage();
                }
            }

            $metadata = $conversation->fresh()->metadata ?? [];
            $pending = data_get($metadata, 'pending_knowledge');
            $savedKnowledge = null;

            if (is_array($result['fact_updates'] ?? null) && $result['fact_updates'] !== []) {
                $existingFacts = data_get($metadata, 'established_facts', []);
                $existingFacts = is_array($existingFacts) ? $existingFacts : [];
                $updates = $result['fact_updates'];
                if (($existingFacts['workflow.ie_active'] ?? false) === true) {
                    foreach ($zebraAnswers->manualKeys() as $manualKey) {
                        if (!array_key_exists($manualKey, $preFacts)) unset($updates[$manualKey]);
                    }
                    if (!array_key_exists('workflow.ie_confirmations', $preFacts)) unset($updates['workflow.ie_confirmations']);
                }
                $metadata['established_facts'] = array_replace($existingFacts, $updates);
                $syncedFields = array_merge($syncedFields, $factSync->sync($lead->fresh(), $updates));

                foreach ($updates as $key => $value) {
                    $definition = $factRegistry->definition((string) $key);
                    if (!$definition || ($definition['scope'] ?? null) !== 'case') continue;
                    if (($definition['storage_type'] ?? null) !== 'lead_decision_fact') continue;

                    $decisionFacts->setLeadFact(
                        $lead->fresh(),
                        (string) $key,
                        $value,
                        'operator',
                        'Captured from Jinx case conversation'
                    );
                }

                $result['fact_updates'] = $updates;
            }

            if (is_array($result['deterministic_ie'] ?? null) && $result['deterministic_ie'] !== []) {
                $metadata['deterministic_ie'] = $result['deterministic_ie'];
                $syncedFields = array_merge($syncedFields, $factSync->syncDeterministicIe($lead->fresh(), $result['deterministic_ie']));
            }
            $syncedFields = array_values(array_unique($syncedFields));
            if (is_array($result['suitability_assessment'] ?? null)) $metadata['last_suitability_assessment'] = $result['suitability_assessment'];
            if (filled($result['proactive_route_signature'] ?? null)) $metadata['last_proactive_route_signature'] = $result['proactive_route_signature'];

            if (($result['confirm_pending_knowledge'] ?? false) && is_array($pending) && filled($pending['content'] ?? null)) {
                $savedKnowledge = AssistantKnowledgeItem::create([
                    'scope' => $pending['scope'] ?? 'company', 'scope_key' => $pending['scope_key'] ?? null,
                    'category' => $pending['category'] ?? 'General', 'title' => $pending['title'] ?? 'Updated rule',
                    'content' => $pending['content'], 'status' => 'active', 'created_by' => $request->user()->id,
                    'metadata' => ['source' => 'jinx_assistant_chat', 'conversation_id' => $conversation->id, 'lead_id' => $lead->id],
                ]);
                unset($metadata['pending_knowledge']);
            }
            if (is_array($result['proposed_knowledge'] ?? null) && filled($result['proposed_knowledge']['content'] ?? null)) $metadata['pending_knowledge'] = $result['proposed_knowledge'];
            if (filled($result['case_summary'] ?? null)) $conversation->summary = $result['case_summary'];

            $decisionPlanState = null;
            $decisionQuestion = null;
            $agentActivity = [];
            $agentResponseId = null;
            $ieCompleteAfter = (($metadata['established_facts']['workflow.ie_complete'] ?? false) === true);

            $conversation->metadata = $metadata;
            $conversation->save();

            if (!$ieCompleteBefore && $ieCompleteAfter) {
                $decisionPlanState = 'awaiting_user';
                $result['reply'] = trim((string) $result['reply'])
                    ."\n\nI’ve saved the I&E. I won’t run the full case reasoning until you ask me to assess the case. You can keep giving me case details in the meantime and I’ll store them as we go.";
            }

            $assistantMessage = AssistantMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant', 'content' => $result['reply'],
                'metadata' => [
                    'knowledge_saved_id' => $savedKnowledge?->id, 'knowledge_proposal' => $result['proposed_knowledge'] ?? null,
                    'fact_updates' => array_replace($preFacts, $result['fact_updates'] ?? []), 'synced_fields' => $syncedFields,
                    'deterministic_ie' => $result['deterministic_ie'] ?? [], 'suitability_assessment' => $result['suitability_assessment'] ?? null,
                    'agent_activity' => $agentActivity, 'agent_response_id' => $agentResponseId,
                    'decision_plan_state' => $decisionPlanState, 'decision_question' => $decisionQuestion,
                ],
            ]);

            return response()->json([
                'ok' => true,
                'message' => ['id' => $assistantMessage->id, 'role' => 'assistant', 'content' => $assistantMessage->content, 'created_at' => $assistantMessage->created_at?->toIso8601String()],
                'knowledge_saved' => $savedKnowledge ? ['id' => $savedKnowledge->id, 'title' => $savedKnowledge->title] : null,
                'knowledge_proposed' => $result['proposed_knowledge'] ?? null,
                'suitability_assessment' => $result['suitability_assessment'] ?? null,
                'synced_fields' => $syncedFields,
                'financial_statement_changed' => in_array('financial_statement', $syncedFields, true),
                'debt_import_complete' => (bool) ($result['debt_import_complete'] ?? false),
                'established_facts' => data_get($conversation->fresh()->metadata, 'established_facts', []),
                'agent_activity' => $agentActivity,
                'decision_plan_state' => $decisionPlanState,
                'decision_question' => $decisionQuestion,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'message' => app()->environment('production') ? 'Jinx Assistant could not respond. Please try again.' : 'Jinx Assistant could not respond. '.$e->getMessage()], 500);
        }
    }

    public function reset(Request $request, Lead $lead): JsonResponse
    {
        $conversation = AssistantConversation::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'title' => 'Jinx Assistant — '.$lead->formattedName(), 'metadata' => []]);
        return response()->json(['ok' => true, 'conversation_id' => $conversation->id]);
    }

    private function directAssistantReply(AssistantConversation $conversation, string $content, array $facts, array $syncedFields = [], array $extra = []): JsonResponse
    {
        $assistantMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'metadata' => array_merge(['fact_updates' => [], 'synced_fields' => $syncedFields], $extra),
        ]);

        return response()->json(array_merge([
            'ok' => true,
            'message' => ['id' => $assistantMessage->id, 'role' => 'assistant', 'content' => $assistantMessage->content, 'created_at' => $assistantMessage->created_at?->toIso8601String()],
            'knowledge_saved' => null,
            'knowledge_proposed' => null,
            'suitability_assessment' => null,
            'synced_fields' => $syncedFields,
            'financial_statement_changed' => in_array('financial_statement', $syncedFields, true),
            'established_facts' => $facts,
        ], $extra));
    }

    private function agentAssistantReply(
        AssistantConversation $conversation,
        JinxAgentService $agent,
        string $message,
        ?string $internalDirective = null,
        array $extra = [],
        string $mode = 'general'
    ): JsonResponse {
        $result = $agent->reply($conversation->fresh(), $message, $internalDirective, $mode);
        $metadata = [
            'agent_activity' => $result['activity'] ?? [],
            'response_id' => $result['response_id'] ?? null,
            'tool_budget_exhausted' => (bool) ($result['tool_budget_exhausted'] ?? false),
        ] + $extra;

        $assistantMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => (string) $result['reply'],
            'metadata' => $metadata,
        ]);

        return response()->json(array_merge([
            'ok' => true,
            'message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $assistantMessage->content,
                'created_at' => $assistantMessage->created_at?->toIso8601String(),
                'metadata' => $metadata,
            ],
            'knowledge_saved' => null,
            'knowledge_proposed' => null,
            'suitability_assessment' => null,
            'synced_fields' => [],
            'financial_statement_changed' => false,
            'agent_activity' => $result['activity'] ?? [],
            'tool_budget_exhausted' => (bool) ($result['tool_budget_exhausted'] ?? false),
            'established_facts' => data_get($conversation->fresh()->metadata, 'established_facts', []),
        ], $extra));
    }

    private function packagingDirective(Lead $lead): string
    {
        return 'The packager has explicitly asked to run case reasoning for lead ID '.$lead->id.', and the readiness questionnaire has completed. The deterministic I&E is already complete. Call assess_iva_case_decision once, then analyse only the serious routes needed in the fixed order Zebra → Lawson Fox → AC (Anchorage Chambers) → Assure → TIG. Do not re-enter or alter I&E figures, inspect Jinx code, or repeat tools without a material reason. Use route-specific I&E, creditor/voting exposure, sourced property/conduct criteria, dynamic operator checks and learned guidance together. Missing documentary evidence is normally a referral action, not a reason to restart the fact questionnaire. Do not invent unsupported Anchorage criteria. If a route conclusion is supportable now, persist it with record_decision_assessment. Use Refresh DMP only where IVA routes are unsuitable. Reply like an experienced colleague: explain the leading route, any genuine blocker, and the practical next actions.';
    }

    private function conversationFor(Request $request, Lead $lead): AssistantConversation
    {
        return AssistantConversation::query()->where('lead_id', $lead->id)->where('user_id', $request->user()->id)->latest('id')->first()
            ?? AssistantConversation::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'title' => 'Jinx Assistant — '.$lead->formattedName(), 'metadata' => []]);
    }

    private function parseCallbackRequest(string $message): ?array
    {
        if (preg_match('/\b(?:call\s*back|callback|call|ring|phone)\b/i', $message) !== 1) return null;
        if (preg_match('/\b(?:tomorrow|today|this\s+(?:morning|afternoon|evening)|(?:next\s+)?(?:mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)|\d{1,2}[\/.-]\d{1,2})\b/i', $message) !== 1) return null;
        if (preg_match('/(?:\bat\s*|\b)(\d{1,2})(?:[:.]([0-5]\d))?\s*(am|pm)\b|\bat\s+(\d{1,2})(?:[:.]([0-5]\d))?\b/i', $message, $tm) !== 1) return null;

        $hour=(int)(($tm[1]??'')!==''?$tm[1]:($tm[4]??0)); $minute=(int)(($tm[2]??'')!==''?$tm[2]:($tm[5]??0)); $ampm=strtolower($tm[3]??'');
        if($ampm==='pm'&&$hour<12)$hour+=12; if($ampm==='am'&&$hour===12)$hour=0;
        if($hour>23)return null;
        $text=strtolower($message); $date=now();
        if(str_contains($text,'tomorrow'))$date=now()->addDay();
        elseif(preg_match('/\b(next\s+)?(mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b/i',$message,$dm)){
            $date=Carbon::parse('next '.$dm[2]);
        } elseif(preg_match('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/',$message,$dm)){
            $year=isset($dm[3])?(int)$dm[3]:(int)now()->year; if($year<100)$year+=2000;
            try{$date=Carbon::create($year,(int)$dm[2],(int)$dm[1]);}catch(Throwable){return null;}
        }
        $when=$date->copy()->setTime($hour,$minute,0);
        if($when->isPast()&&!str_contains($text,'today'))return null;
        if($when->isPast()&&str_contains($text,'today'))return null;

        $comments='';
        if(preg_match('/\b(?:because|regarding|about|re|note)\b[:\s-]+(.+)$/i',$message,$cm))$comments=trim($cm[1]);
        elseif(preg_match('/\b(to\s+be\s+.+)$/i',$message,$cm))$comments=trim($cm[1]);
        return ['when'=>$when,'comments'=>$comments];
    }

    private function isIeStartRequest(string $message): bool
    {
        return preg_match('/\b(?:run|start|carry\s*out|calculate|complete|do)\b.*\b(?:i\s*(?:&|and)\s*e|income\s*(?:&|and)\s*expenditure)\b/i', $message) === 1;
    }

    private function isPackagingRequest(string $message): bool
    {
        return preg_match('/\b(?:assess|assessment|review|route|routing|suitable|suitability|refer|referral|package|packaging|which\s+(?:ip|route)|iva\s+fit|dmp\s+fallback)\b/i', $message) === 1;
    }

    private function isContinueCaseRequest(string $message): bool
    {
        return preg_match('/\b(?:continue|carry\s+on|go\s+ahead|proceed|keep\s+going|what(?:\s+do\s+we\s+do)?\s+next|next\s+step)\b/i', $message) === 1;
    }

    private function lowercaseFirst(string $value): string
    {
        $value = trim($value);
        if ($value === '') return $value;
        return mb_strtolower(mb_substr($value,0,1)).mb_substr($value,1);
    }

    private function withoutIeFacts(array $facts): array
    {
        foreach (array_keys($facts) as $key) if (preg_match('/^(?:workflow|calculation|income|household|housing|transport|utilities|sfs|other)\./', (string) $key)) unset($facts[$key]);
        return $facts;
    }

    private function explicitHouseholdFacts(string $message): array
    {
        $facts = [];
        $text = strtolower($message);
        if (preg_match('/\bno\s+partner\b/', $text)) $facts['household.partner_exists'] = false;
        elseif (preg_match('/\b(?:has|with)\s+(?:a\s+)?partner\b/', $text)) $facts['household.partner_exists'] = true;
        if (preg_match('/\b(\d+)\s+(?:resident\s+)?children?\b/', $text, $m)) $facts['household.children_count'] = (int) $m[1];
        elseif (preg_match('/\bno\s+(?:resident\s+)?children\b/', $text)) { $facts['household.children_count'] = 0; $facts['household.children_ages'] = []; }
        if (preg_match('/\bchildren?\s+aged?\s+([0-9,\s&and]+)/', $text, $m)) {
            preg_match_all('/\d+/', $m[1], $ages);
            $parsed = array_map('intval', $ages[0] ?? []);
            if ($parsed !== []) { $facts['household.children_ages'] = $parsed; $facts['household.children_count'] = count($parsed); }
        }
        return $facts;
    }
}
