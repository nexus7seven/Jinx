<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Models\Lead;
use App\Services\JinxAssistantService;
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
            'messages' => $conversation->messages()
                ->latest('id')
                ->limit(60)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (AssistantMessage $message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function send(Request $request, Lead $lead, JinxAssistantService $assistant): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
        ]);

        $conversation = $this->conversationFor($request, $lead);

        $userMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => trim($validated['message']),
        ]);

        try {
            $result = $assistant->reply($conversation->fresh(), $userMessage->content);

            $metadata = $conversation->metadata ?? [];
            $pending = data_get($metadata, 'pending_knowledge');
            $savedKnowledge = null;

            if (($result['confirm_pending_knowledge'] ?? false) && is_array($pending) && filled($pending['content'] ?? null)) {
                $savedKnowledge = AssistantKnowledgeItem::create([
                    'scope' => $pending['scope'] ?? 'company',
                    'scope_key' => $pending['scope_key'] ?? null,
                    'category' => $pending['category'] ?? 'General',
                    'title' => $pending['title'] ?? 'Updated rule',
                    'content' => $pending['content'],
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                    'metadata' => [
                        'source' => 'jinx_assistant_chat',
                        'conversation_id' => $conversation->id,
                    ],
                ]);

                unset($metadata['pending_knowledge']);
            }

            if (is_array($result['proposed_knowledge'] ?? null) && filled($result['proposed_knowledge']['content'] ?? null)) {
                $metadata['pending_knowledge'] = $result['proposed_knowledge'];
            }

            if (filled($result['case_summary'] ?? null)) {
                $conversation->summary = $result['case_summary'];
            }

            $conversation->metadata = $metadata;
            $conversation->save();

            $assistantMessage = AssistantMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $result['reply'],
                'metadata' => [
                    'knowledge_saved_id' => $savedKnowledge?->id,
                    'knowledge_proposal' => $result['proposed_knowledge'] ?? null,
                ],
            ]);

            return response()->json([
                'ok' => true,
                'message' => [
                    'id' => $assistantMessage->id,
                    'role' => 'assistant',
                    'content' => $assistantMessage->content,
                    'created_at' => $assistantMessage->created_at?->toIso8601String(),
                ],
                'knowledge_saved' => $savedKnowledge ? [
                    'id' => $savedKnowledge->id,
                    'title' => $savedKnowledge->title,
                ] : null,
                'knowledge_proposed' => $result['proposed_knowledge'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => app()->environment('production')
                    ? 'Jinx Assistant could not respond. Please try again.'
                    : 'Jinx Assistant could not respond. '.$e->getMessage(),
            ], 500);
        }
    }

    public function reset(Request $request, Lead $lead): JsonResponse
    {
        $conversation = AssistantConversation::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'title' => 'Jinx Assistant — '.$lead->formattedName(),
            'metadata' => [],
        ]);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
        ]);
    }

    private function conversationFor(Request $request, Lead $lead): AssistantConversation
    {
        return AssistantConversation::query()
            ->where('lead_id', $lead->id)
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first()
            ?? AssistantConversation::create([
                'lead_id' => $lead->id,
                'user_id' => $request->user()->id,
                'title' => 'Jinx Assistant — '.$lead->formattedName(),
                'metadata' => [],
            ]);
    }
}
