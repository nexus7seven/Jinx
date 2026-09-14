<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Models\Lead;
use App\Services\AssistantLeadFactSyncService;
use App\Services\JinxAssistantService;
use App\Services\ZebraIeInterviewAnswerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class JinxAssistantController extends Controller
{
    public function bootstrap(Request $request, Lead $lead): JsonResponse
    {
        $conversation = $this->conversationFor($request, $lead);
        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
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

    public function send(Request $request, Lead $lead, JinxAssistantService $assistant, AssistantLeadFactSyncService $factSync, ZebraIeInterviewAnswerService $zebraAnswers): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:12000']]);
        $messageText = trim($validated['message']);
        $conversation = $this->conversationFor($request, $lead);
        $userMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id, 'role' => 'user', 'content' => $messageText,
        ]);

        try {
            $metadata = $conversation->metadata ?? [];
            $existingFacts = data_get($metadata, 'established_facts', []);
            $existingFacts = is_array($existingFacts) ? $existingFacts : [];

            // Hard fresh-start guard: if the Financial Statement already contains meaningful
            // I&E data, do not silently reuse or overwrite it. Ask the packager first.
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

                    return $this->directAssistantReply(
                        $conversation,
                        'Financial Statement cleared. '.$zebraAnswers->nextQuestion($metadata['established_facts']),
                        $metadata['established_facts'],
                        $syncedFields
                    );
                }

                if (in_array($answer, ['no','n','nope','keep it','keep','use existing'], true)) {
                    unset($metadata['pending_ie_reset_confirmation']);
                    $startFacts = $zebraAnswers->start($existingFacts);
                    $metadata['established_facts'] = array_replace($existingFacts, $startFacts);
                    $conversation->metadata = $metadata;
                    $conversation->save();

                    $next = $zebraAnswers->nextQuestion($metadata['established_facts']);
                    $reply = $next !== null
                        ? 'Okay, I’ll keep the existing Financial Statement. '.$next
                        : 'Okay, I’ll keep the existing Financial Statement and use the recorded I&E figures.';

                    return $this->directAssistantReply($conversation, $reply, $metadata['established_facts']);
                }

                return $this->directAssistantReply(
                    $conversation,
                    'The Financial Statement already contains I&E data. Do you want me to reset the complete Financial Statement and start fresh? Yes or no.',
                    $existingFacts
                );
            }

            if ($this->isIeStartRequest($messageText) && $factSync->hasPopulatedIe($lead->fresh())) {
                $metadata['pending_ie_reset_confirmation'] = true;
                $conversation->metadata = $metadata;
                $conversation->save();

                return $this->directAssistantReply(
                    $conversation,
                    'The Financial Statement already contains I&E data. Do you want me to reset the complete Financial Statement and start fresh? Yes or no.',
                    $existingFacts
                );
            }

            // Starting an I&E is deterministic. Do this before the model sees the turn so
            // "run an i and e" cannot be interpreted as a request to inspect stale FS data.
            $startFacts = $this->isIeStartRequest($messageText) ? $zebraAnswers->start($existingFacts) : [];
            $householdFacts = $this->explicitHouseholdFacts($messageText);
            $factsForAnswer = array_replace($existingFacts, $startFacts, $householdFacts);

            // If explicit household facts were supplied outside the one-question flow, sign
            // them as established so the deterministic state machine can legitimately skip them.
            if (($factsForAnswer['workflow.ie_active'] ?? false) === true && $householdFacts !== []) {
                $factsForAnswer = array_replace($factsForAnswer, $zebraAnswers->start(array_replace($existingFacts, $householdFacts)), [
                    'workflow.ie_active' => true,
                    'workflow.income_complete' => $startFacts['workflow.income_complete'] ?? ($existingFacts['workflow.income_complete'] ?? false),
                    'workflow.ie_complete' => false,
                ]);
            }

            // Persist the answer to the exact current Zebra checkpoint before asking the model.
            $answerFacts = $zebraAnswers->extract($lead, $factsForAnswer, $messageText);
            $preFacts = array_replace($startFacts, $householdFacts, $answerFacts);

            $syncedFields = [];
            if ($preFacts !== []) {
                $metadata['established_facts'] = array_replace($existingFacts, $preFacts);
                $conversation->metadata = $metadata;
                $conversation->save();
                $syncedFields = array_merge($syncedFields, $factSync->sync($lead->fresh(), $preFacts));
            }

            $result = $assistant->reply($conversation->fresh(), $userMessage->content);
            $metadata = $conversation->fresh()->metadata ?? [];
            $pending = data_get($metadata, 'pending_knowledge');
            $savedKnowledge = null;

            if (is_array($result['fact_updates'] ?? null) && $result['fact_updates'] !== []) {
                $existingFacts = data_get($metadata, 'established_facts', []);
                $existingFacts = is_array($existingFacts) ? $existingFacts : [];
                $updates = $result['fact_updates'];

                // During an active deterministic I&E the model is not allowed to establish
                // manual checkpoints. Only the answer service above may do that. This prevents
                // stale Financial Statement values or model guesses from skipping income/questions.
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
                'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $result['reply'],
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
                'established_facts' => data_get($metadata, 'established_facts', []),
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => app()->environment('production') ? 'Jinx Assistant could not respond. Please try again.' : 'Jinx Assistant could not respond. '.$e->getMessage(),
            ], 500);
        }
    }

    public function reset(Request $request, Lead $lead): JsonResponse
    {
        $conversation = AssistantConversation::create([
            'lead_id' => $lead->id, 'user_id' => $request->user()->id,
            'title' => 'Jinx Assistant — '.$lead->formattedName(), 'metadata' => [],
        ]);
        return response()->json(['ok' => true, 'conversation_id' => $conversation->id]);
    }

    private function directAssistantReply(AssistantConversation $conversation, string $content, array $facts, array $syncedFields = []): JsonResponse
    {
        $assistantMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'metadata' => ['fact_updates' => [], 'synced_fields' => $syncedFields],
        ]);

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $assistantMessage->content,
                'created_at' => $assistantMessage->created_at?->toIso8601String(),
            ],
            'knowledge_saved' => null,
            'knowledge_proposed' => null,
            'suitability_assessment' => null,
            'synced_fields' => $syncedFields,
            'financial_statement_changed' => in_array('financial_statement', $syncedFields, true),
            'established_facts' => $facts,
        ]);
    }

    private function conversationFor(Request $request, Lead $lead): AssistantConversation
    {
        return AssistantConversation::query()->where('lead_id', $lead->id)->where('user_id', $request->user()->id)->latest('id')->first()
            ?? AssistantConversation::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'title' => 'Jinx Assistant — '.$lead->formattedName(), 'metadata' => []]);
    }

    private function isIeStartRequest(string $message): bool
    {
        return preg_match('/\b(?:run|start|carry\s*out|calculate|complete|do)\b.*\b(?:i\s*(?:&|and)\s*e|income\s*(?:&|and)\s*expenditure)\b/i', $message) === 1;
    }

    private function withoutIeFacts(array $facts): array
    {
        foreach (array_keys($facts) as $key) {
            if (preg_match('/^(?:workflow|calculation|income|household|housing|transport|utilities|sfs|other)\./', (string) $key)) unset($facts[$key]);
        }
        return $facts;
    }

    private function explicitHouseholdFacts(string $message): array
    {
        $facts = [];
        $text = strtolower($message);

        if (preg_match('/\bno\s+partner\b/', $text)) $facts['household.partner_exists'] = false;
        elseif (preg_match('/\b(?:has|with)\s+(?:a\s+)?partner\b/', $text)) $facts['household.partner_exists'] = true;

        if (preg_match('/\b(\d+)\s+(?:resident\s+)?children?\b/', $text, $m)) {
            $facts['household.children_count'] = (int) $m[1];
        } elseif (preg_match('/\bno\s+(?:resident\s+)?children\b/', $text)) {
            $facts['household.children_count'] = 0;
            $facts['household.children_ages'] = [];
        }

        if (preg_match('/\bchildren?\s+aged?\s+([0-9,\s&and]+)/', $text, $m)) {
            preg_match_all('/\d+/', $m[1], $ages);
            $parsed = array_map('intval', $ages[0] ?? []);
            if ($parsed !== []) {
                $facts['household.children_ages'] = $parsed;
                $facts['household.children_count'] = count($parsed);
            }
        }

        return $facts;
    }
}