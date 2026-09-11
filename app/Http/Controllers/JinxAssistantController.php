<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Models\Lead;
use App\Services\JinxAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class JinxAssistantController extends Controller
{
    public function index(Request $request, ?Lead $lead = null): View
    {
        $conversation = AssistantConversation::query()
            ->where('user_id', $request->user()->id)
            ->when($lead, fn ($q) => $q->where('lead_id', $lead->id), fn ($q) => $q->whereNull('lead_id'))
            ->latest('id')
            ->first();

        if (! $conversation) {
            $conversation = AssistantConversation::create([
                'lead_id' => $lead?->id,
                'user_id' => $request->user()->id,
                'title' => $lead ? 'Jinx Assistant — '.$lead->formattedName() : 'Jinx Assistant',
                'metadata' => [],
            ]);
        }

        $messages = $conversation->messages()->get();
        $knowledgeCount = AssistantKnowledgeItem::active()->count();

        return view('assistant.index', compact('conversation', 'messages', 'lead', 'knowledgeCount'));
    }

    public function send(Request $request, AssistantConversation $conversation, JinxAssistantService $assistant): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
        ]);

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
                'message' => 'Jinx Assistant could not respond. '.$e->getMessage(),
            ], 500);
        }
    }

    public function newConversation(Request $request, ?Lead $lead = null)
    {
        $conversation = AssistantConversation::create([
            'lead_id' => $lead?->id,
            'user_id' => $request->user()->id,
            'title' => $lead ? 'Jinx Assistant — '.$lead->formattedName() : 'Jinx Assistant',
            'metadata' => [],
        ]);

        return redirect()->route('assistant.index', $lead ? ['lead' => $lead->id] : []);
    }
}
