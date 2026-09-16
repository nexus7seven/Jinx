<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Models\Lead;
use App\Services\AssistantLeadFactSyncService;
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

    public function send(Request $request, Lead $lead, JinxAssistantService $assistant, AssistantLeadFactSyncService $factSync, ZebraIeInterviewAnswerService $zebraAnswers, VicidialCallbackService $callbacks): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:12000']]);
        $messageText = trim($validated['message']);
        $conversation = $this->conversationFor($request, $lead);
        $userMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'active_callback' => $callback, 'role' => 'user', 'content' => $messageText,
        ]);

        try {
            if ($callback = $this->parseCallbackRequest($messageText)) {
                $scheduled = $callbacks->schedule($lead->fresh(), $callback['when'], $callback['comments']);
                $reply = 'Callback booked for '.$callback['when']->format('D j M \a\t H:i').'.';
                if ($callback['comments'] !== '') $reply .= ' Note: '.$callback['comments'];
                return $this->directAssistantReply($conversation, $reply, [], ['vicidial_callback']);
            }

            $metadata = $conversation->metadata ?? [];
            $existingFacts = data_get($metadata, 'established_facts', []);
            $existingFacts = is_array($existingFacts) ? $existingFacts : [];

            if (($metadata['pending_ie_reset_confirmation'] ?? false) === true) {
                $answer = strtolower(trim($messageText));
                if (in_array($answer, ['yes','y','yeah','yep','reset','start fresh','fresh'], true)) {
                    $syncedFields = $factSync->resetIe($lead->fresh());
                    $cleanFacts = $this->withoutIeFacts($existingFacts);
                    $startFacts = $zebraAnswers->start($cleanFacts);
                    $metadata['established_facts'] = array_replace($cleanFacts, $startFacts);
                    unset($metadata['pending_ie_reset_confirmation'], $metadata['deterministic_ie'], $metadata['last_suitability_assessment'], $metadata['last_proactive_route_signature']);
                    $conversation->metadata = $metadata;
                    $conversation->save();
                    return $this->directAssistantReply($conversation, 'Financial Statement cleared. '.$zebraAnswers->nextQuestion($metadata['established_facts']), $metadata['established_facts'], $syncedFields);
                }
                if (in_array($answer, ['no','n','nope','keep it','keep','use existing'], true)) {
                    unset($metadata['pending_ie_reset_confirmation']);
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
            $conversation->metadata = $metadata;
            $conversation->save();

            $assistantMessage = AssistantMessage::create([
                'conversation_id' => $conversation->id,
            'active_callback' => $callback, 'role' => 'assistant', 'content' => $result['reply'],
                'metadata' => [
                    'knowledge_saved_id' => $savedKnowledge?->id, 'knowledge_proposal' => $result['proposed_knowledge'] ?? null,
                    'fact_updates' => array_replace($preFacts, $result['fact_updates'] ?? []), 'synced_fields' => $syncedFields,
                    'deterministic_ie' => $result['deterministic_ie'] ?? [], 'suitability_assessment' => $result['suitability_assessment'] ?? null,
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
                'established_facts' => data_get($metadata, 'established_facts', []),
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

    private function directAssistantReply(AssistantConversation $conversation, string $content, array $facts, array $syncedFields = []): JsonResponse
    {
        $assistantMessage = AssistantMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $content, 'metadata' => ['fact_updates' => [], 'synced_fields' => $syncedFields]]);
        return response()->json([
            'ok' => true,
            'message' => ['id' => $assistantMessage->id, 'role' => 'assistant', 'content' => $assistantMessage->content, 'created_at' => $assistantMessage->created_at?->toIso8601String()],
            'knowledge_saved' => null, 'knowledge_proposed' => null, 'suitability_assessment' => null,
            'synced_fields' => $syncedFields, 'financial_statement_changed' => in_array('financial_statement', $syncedFields, true), 'established_facts' => $facts,
        ]);
    }

    private function conversationFor(Request $request, Lead $lead): AssistantConversation
    {
        return AssistantConversation::query()->where('lead_id', $lead->id)->where('user_id', $request->user()->id)->latest('id')->first()
            ?? AssistantConversation::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'title' => 'Jinx Assistant — '.$lead->formattedName(), 'metadata' => []]);
    }

    private function parseCallbackRequest(string $message): ?array
    {
        if (preg_match('/\b(?:call\s*back|callback|call|ring|phone)\b/i', $message) !== 1) return null;
        if (preg_match('/\b(?:tomorrow|today|this\s+(?:morning|afternoon|evening)|next\s+(?:mon|tue|wed|thu|fri|sat|sun)|\d{1,2}[\/.-]\d{1,2})\b/i', $message) !== 1) return null;
        if (preg_match('/(?:\bat\s*|\b)(\d{1,2})(?:[:.]([0-5]\d))?\s*(am|pm)\b|\bat\s+(\d{1,2})(?:[:.]([0-5]\d))?\b/i', $message, $tm) !== 1) return null;

        $hour=(int)(($tm[1]??'')!==''?$tm[1]:($tm[4]??0)); $minute=(int)(($tm[2]??'')!==''?$tm[2]:($tm[5]??0)); $ampm=strtolower($tm[3]??'');
        if($ampm==='pm'&&$hour<12)$hour+=12; if($ampm==='am'&&$hour===12)$hour=0;
        if($hour>23)return null;
        $text=strtolower($message); $date=now();
        if(str_contains($text,'tomorrow'))$date=now()->addDay();
        elseif(preg_match('/\bnext\s+(mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b/i',$message,$dm)){
            $date=Carbon::parse('next '.$dm[1]);
        } elseif(preg_match('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/',$message,$dm)){
            $year=isset($dm[3])?(int)$dm[3]:(int)now()->year; if($year<100)$year+=2000;
            try{$date=Carbon::create($year,(int)$dm[2],(int)$dm[1]);}catch(Throwable){return null;}
        }
        $when=$date->copy()->setTime($hour,$minute,0);
        if($when->isPast()&&!str_contains($text,'today'))return null;
        if($when->isPast()&&str_contains($text,'today'))return null;

        $comments='';
        if(preg_match('/\b(?:because|regarding|about|re|note)\b[:\s-]+(.+)$/i',$message,$cm))$comments=trim($cm[1]);
        return ['when'=>$when,'comments'=>$comments];
    }

    private function isIeStartRequest(string $message): bool
    {
        return preg_match('/\b(?:run|start|carry\s*out|calculate|complete|do)\b.*\b(?:i\s*(?:&|and)\s*e|income\s*(?:&|and)\s*expenditure)\b/i', $message) === 1;
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
