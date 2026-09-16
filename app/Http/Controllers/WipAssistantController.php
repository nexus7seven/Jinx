<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantKnowledgeItem;
use App\Models\AssistantMessage;
use App\Models\Lead;
use App\Services\JinxAssistantService;
use App\Services\VicidialDialActivityService;
use App\Services\LeadChecklistService;
use App\Services\VicidialCallbackService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WipAssistantController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        $conversation=$this->conversationFor($request);
        return response()->json(['ok'=>true,'knowledge_count'=>AssistantKnowledgeItem::active()->count(),'messages'=>$conversation->messages()->latest('id')->limit(60)->get()->reverse()->values()->map(fn($m)=>['id'=>$m->id,'role'=>$m->role,'content'=>$m->content])]);
    }

    public function send(Request $request, JinxAssistantService $assistant, VicidialCallbackService $callbacks, VicidialDialActivityService $dialActivity, LeadChecklistService $checklists): JsonResponse
    {
        $text=trim($request->validate(['message'=>['required','string','max:12000']])['message']);
        $conversation=$this->conversationFor($request);
        AssistantMessage::create(['conversation_id'=>$conversation->id,'role'=>'user','content'=>$text]);
        try {
            if(($action=$this->callbackAction($text))!==null){
                $scheduled=$callbacks->schedule($action['lead'],$action['when'],$action['comments']);
                $reply='Callback booked for '.$action['lead']->formattedName().' on '.$action['when']->format('D j M \\a\\t H:i').'.';
                if($action['comments']!=='')$reply.=' I’ve kept the call notes with the callback.';
                return $this->reply($conversation,$reply);
            }
            $deskContext=$this->deskContext($callbacks,$dialActivity,$checklists);
            $result=$assistant->reply($conversation->fresh(),$text,$deskContext);
            if(is_array($result['action']??null) && ($result['action']['type']??null)==='schedule_callback'){
                $lead=$this->resolveActionLead($result['action']);
                if(!$lead)return $this->reply($conversation,'I understood the callback request, but I could not identify one WIP case confidently. Tell me the client name.');
                $when=Carbon::parse((string)$result['action']['callback_at']);
                $callbacks->schedule($lead,$when,(string)($result['action']['notes']??''));
                return $this->reply($conversation,'Callback booked for '.$lead->formattedName().' on '.$when->format('D j M \a\t H:i').(filled($result['action']['notes']??null)?'. Notes: '.trim((string)$result['action']['notes']):'.'));
            }
            return $this->reply($conversation,(string)$result['reply']);
        } catch(Throwable $e){ report($e); return response()->json(['ok'=>false,'message'=>'Jinx Assistant could not respond. Please try again.'],500); }
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

    private function reply(AssistantConversation $conversation,string $content): JsonResponse
    {
        $message=AssistantMessage::create(['conversation_id'=>$conversation->id,'role'=>'assistant','content'=>$content]);
        return response()->json(['ok'=>true,'message'=>['id'=>$message->id,'role'=>'assistant','content'=>$content]]);
    }
    private function callbackAction(string $text): ?array
    {
        if(preg_match('/\b(?:call\s*back|callback|call|ring|phone)\b/i',$text)!==1)return null;
        $lead=Lead::query()->whereNotNull('vicidial_lead_id')->get()->first(function(Lead $lead)use($text){
            $name=trim($lead->formattedName());
            return $name!==''&&preg_match('/\b'.preg_quote($name,'/').'\b/i',$text)===1;
        });
        if(!$lead)return null;
        if(preg_match('/\b(tomorrow|today)\b/i',$text,$day)!==1)return null;
        if(preg_match('/(?:\bat\s*|\b)(\d{1,2})(?:[:.]([0-5]\d))?\s*(am|pm)\b|\bat\s+(\d{1,2})(?:[:.]([0-5]\d))?\b/i',$text,$tm)!==1)return null;
        $hour=(int)(($tm[1]??'')!==''?$tm[1]:($tm[4]??0));
        $minute=(int)(($tm[2]??'')!==''?$tm[2]:($tm[5]??0));
        $ampm=strtolower($tm[3]??'');
        if($ampm==='pm'&&$hour<12)$hour+=12;
        if($ampm==='am'&&$hour===12)$hour=0;
        if($hour>23)return null;
        $when=now()->addDays(strtolower($day[1])==='tomorrow'?1:0)->setTime($hour,$minute,0);
        if($when->isPast())return null;
        $comments=trim($text);
        return compact('lead','when','comments');
    }
    private function deskContext(VicidialCallbackService $callbacks, VicidialDialActivityService $dialActivity, LeadChecklistService $checklists): array
    {
        $leads=Lead::query()->whereNotIn('wip_status',Lead::WIP_STATUSES_EXCLUDED_FROM_ACTIVE_TAB)->get();
        foreach($leads as $lead)$checklists->syncForLead($lead);
        $dialActivity->attachLastDialledToLeads($leads);
        $counts=\App\Models\LeadChecklistItem::query()->selectRaw('lead_id, SUM(CASE WHEN is_complete = 0 THEN 1 ELSE 0 END) outstanding')->whereIn('lead_id',$leads->pluck('id'))->groupBy('lead_id')->pluck('outstanding','lead_id');
        $callbackByLead=collect($callbacks->activeForJinxLeads())->keyBy('lead_id');
        return ['scope'=>'WIP_DESK','today'=>now()->toDateString(),'active_case_count'=>$leads->count(),'callbacks'=>$callbackByLead->values()->all(),'cases'=>$leads->map(fn(Lead $l)=>['id'=>$l->id,'name'=>$l->formattedName(),'status'=>$l->wip_status,'source'=>$l->source,'outstanding'=>(int)($counts[$l->id]??0),'last_dialled_at'=>$l->last_dialled_at?->toDateTimeString(),'callback'=>$callbackByLead->get($l->id)])->values()->all()];
    }

    private function resolveActionLead(array $action): ?Lead
    {
        if(is_numeric($action['lead_id']??null))return Lead::find((int)$action['lead_id']);
        $name=trim((string)($action['lead_name']??'')); if($name==='')return null;
        $matches=Lead::query()->whereNotNull('vicidial_lead_id')->get()->filter(fn(Lead $l)=>strcasecmp(trim($l->formattedName()),$name)===0);
        return $matches->count()===1?$matches->first():null;
    }

}
