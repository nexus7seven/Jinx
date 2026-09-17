<?php

namespace App\Services;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class JinxAgentService
{
    public function __construct(private readonly JinxAgentToolService $tools) {}

    public function reply(AssistantConversation $conversation,string $message): array
    {
        $apiKey=(string)config('services.jinx_assistant.api_key');$model=(string)config('services.jinx_assistant.model');
        if($apiKey===''||$model==='')throw new RuntimeException('Jinx Agent is not configured.');
        $instructions=$this->instructions();
        $history=$conversation->messages()->latest('id')->limit(40)->get()->reverse()->values()->map(fn(AssistantMessage $m)=>['role'=>$m->role==='assistant'?'assistant':'user','content'=>$m->content])->all();
        if($history===[])$history=[['role'=>'user','content'=>$message]];
        $payload=['model'=>$model,'instructions'=>$instructions,'input'=>$history,'tools'=>$this->tools->definitions(),'tool_choice'=>'auto','parallel_tool_calls'=>false,'max_output_tokens'=>2600,'include'=>['web_search_call.action.sources']];
        $response=$this->post($apiKey,$payload);$activity=[];
        for($round=0;$round<8;$round++){
            $calls=collect($response['output']??[])->filter(fn($x)=>($x['type']??null)==='function_call')->values();
            if($calls->isEmpty())return ['reply'=>$this->outputText($response),'activity'=>$activity,'response_id'=>$response['id']??null];
            $outputs=[];
            foreach($calls as $call){$name=(string)($call['name']??'');$args=json_decode((string)($call['arguments']??'{}'),true);$args=is_array($args)?$args:[];$activity[]=$name;
                try{$result=$this->tools->execute($name,$args);$output=['ok'=>true,'result'=>$result];}catch(Throwable $e){report($e);$output=['ok'=>false,'error'=>$e->getMessage()];}
                $outputs[]=['type'=>'function_call_output','call_id'=>$call['call_id'],'output'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
            }
            $response=$this->post($apiKey,['model'=>$model,'instructions'=>$instructions,'previous_response_id'=>$response['id'],'input'=>$outputs,'tools'=>$this->tools->definitions(),'tool_choice'=>'auto','parallel_tool_calls'=>false,'max_output_tokens'=>2600,'include'=>['web_search_call.action.sources']]);
        }
        throw new RuntimeException('Jinx Agent exceeded its tool-call limit: '.implode(', ',$activity));
    }

    private function post(string $apiKey,array $payload): array
    {
        $r=Http::timeout(90)->withToken($apiKey)->acceptJson()->post('https://api.openai.com/v1/responses',$payload);
        if(!$r->successful())throw new RuntimeException('Agent provider error: '.$r->status().' '.$r->body());return $r->json();
    }

    private function outputText(array $r): string
    {
        if(is_string($r['output_text']??null)&&trim($r['output_text'])!=='')return trim($r['output_text']);
        $parts=[];foreach($r['output']??[] as $item)if(($item['type']??null)==='message')foreach($item['content']??[] as $c)if(($c['type']??null)==='output_text'&&filled($c['text']??null))$parts[]=$c['text'];
        if(!$parts)throw new RuntimeException('Jinx Agent returned no final answer.');return trim(implode("\n",$parts));
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You are Jinx Agent: the user's highly capable operational colleague inside their IVA CRM. Behave like an experienced admin employee, analyst, IVA case packager and general-purpose research assistant in one conversation.

You have tools. Use them proactively rather than saying you cannot access information that a tool can retrieve. For CRM/dialler questions, inspect the real system before answering. For current or external facts, use web search. For company, partner or IP packaging rules, search_internal_knowledge is authoritative and takes precedence over generic internet guidance. Clearly distinguish internal rules from external research when both matter.

You may perform ordinary case-level operational writes exposed by tools when the user's instruction is clear: case fields, I&E facts/calculations, debts, checklist items, notes, WIP status, callbacks and VICIdial-to-Jinx imports. For debts, search creditors before choosing a creditor ID. Use deterministic I&E and target-DI tools for calculations rather than doing those calculations yourself. Never claim a write succeeded until its tool returns success. If a write tool fails, say so accurately. Do not invent database values, case facts, rules or research results.

Reason across multiple tools when necessary. A question such as why a WIP client is still dialling may require Jinx case lookup plus VICIdial state. A request to assess a case for a partner may require the case, deterministic I&E calculation and internal knowledge. For a packaging review, inspect the case and checklist and search the applicable internal rules; identify missing evidence without inventing requirements. Use follow-up questions only when a genuinely necessary fact cannot be obtained from tools or context.

Be conversational, concise and useful. Do not expose hidden chain-of-thought. You may briefly say what you checked. Current timezone is Europe/London. Today is supplied by the platform clock.
PROMPT;
    }
}
