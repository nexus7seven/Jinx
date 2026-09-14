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

            // Persist facts that can be resolved deterministically before asking the model.
            // This includes the answer to the exact Zebra interview question currently due,
            // so a bare answer such as "110" cannot be lost or cause the same question to repeat.
            $preFacts = array_replace(
                $this->explicitHouseholdFacts($messageText),
                $zebraAnswers->extract($lead, $existingFacts, $messageText)
            );

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
                $metadata['established_facts'] = array_replace($existingFacts, $result['fact_updates']);
                $syncedFields = array_merge($syncedFields, $factSync->sync($lead->fresh(), $result['fact_updates']));
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

    private function conversationFor(Request $request, Lead $lead): AssistantConversation
    {
        return AssistantConversation::query()->where('lead_id', $lead->id)->where('user_id', $request->user()->id)->latest('id')->first()
            ?? AssistantConversation::create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'title' => 'Jinx Assistant — '.$lead->formattedName(), 'metadata' => []]);
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
