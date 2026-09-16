<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Services\JinxAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WipAssistantController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        $conversation=$this->conversationFor($request);
        return response()->json(['ok'=>true,'knowledge_count'=>AssistantKnowledgeItem::active()->count(),'messages'=>$conversation->messages()->latest('id')->limit(60)->get()->reverse()->values()->map(fn($m)=>['id'=>$m->id,'role'=>$m->role,'content'=>$m->content,'metadata'=>$m->metadata])]);
    }

    public function send(Request $request, JinxAgentService $agent): JsonResponse
    {
        $text=trim($request->validate(['message'=>['required','string','max:12000']])['message']);
        $conversation=$this->conversationFor($request);
        AssistantMessage::create(['conversation_id'=>$conversation->id,'role'=>'user','content'=>$text]);
        try {
            $result=$agent->reply($conversation->fresh(),$text);
            return $this->reply($conversation,(string)$result['reply'],['agent_activity'=>$result['activity']??[],'response_id'=>$result['response_id']??null]);
        } catch(Throwable $e){ report($e); return response()->json(['ok'=>false,'message'=>'Jinx Agent could not complete that request: '.$e->getMessage()],500); }
    }

    public function reset(Request $request): JsonResponse
    {
        AssistantConversation::create(['lead_id'=>null,'user_id'=>$request->user()->id,'title'=>'Jinx WIP Assistant','metadata'=>[]]);
        return response()->json(['ok'=>true]);
    }

    private function conversationFor(Request $request): AssistantConversation
    {
        return AssistantConversation::query()->whereNull('lead_id')->where('user_id',$request->user()->id)->latest('id')->first()
            ?? AssistantConversation::create(['lead_id'=>null,'user_id'=>$request->user()->id,'title'=>'Jinx WIP Assistant','metadata'=>[]]);
    }

    private function reply(AssistantConversation $conversation,string $content,array $metadata=[]): JsonResponse
    {
        $message=AssistantMessage::create(['conversation_id'=>$conversation->id,'role'=>'assistant','content'=>$content,'metadata'=>$metadata]);
        return response()->json(['ok'=>true,'message'=>['id'=>$message->id,'role'=>'assistant','content'=>$content,'metadata'=>$metadata]]);
    }





}
