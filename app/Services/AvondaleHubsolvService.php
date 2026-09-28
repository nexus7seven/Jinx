<?php
namespace App\Services;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class AvondaleHubsolvService {
 public function preflight(Lead $lead): array {
  $missing=[]; foreach(['title','first_name','last_name','dob','phone_number','email','address_line_1','postcode'] as $f) if(trim((string)$lead->{$f})==='')$missing[]=$f;
  $titles=['mr','mrs','miss','ms','master','sir','madam','dr','professor','rev','sgt','unknown','mx','mics','ind']; if($lead->title && !in_array(strtolower(trim((string)$lead->title)),$titles,true))$missing[]='valid_title';
  if($lead->dob && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$lead->dob)))$missing[]='valid_dob';
  if($lead->email && !filter_var($lead->email,FILTER_VALIDATE_EMAIL))$missing[]='valid_email';
  $debts=DB::table('debts as d')->leftJoin('creditors as c','c.id','=','d.creditor_id')->where('d.lead_id',$lead->id)->orderBy('d.id')->get(['d.id','d.balance','d.reference','c.name as creditor']);
  $refs=[];$creditors=[];$balances=[];foreach($debts as $d){if(trim((string)$d->reference)==='')$refs[]=$d->id;if(trim((string)$d->creditor)==='')$creditors[]=$d->id;if(!is_numeric($d->balance)||(float)$d->balance<0)$balances[]=$d->id;}
  $cfg=$this->config(); return ['ready'=>!$missing&&!$refs&&!$creditors&&!$balances&&$debts->count()>0,'missing_fields'=>array_values(array_unique($missing)),'missing_reference_ids'=>$refs,'missing_creditor_ids'=>$creditors,'invalid_balance_ids'=>$balances,'debt_count'=>$debts->count(),'campaign_id'=>$cfg['campaign'],'lead_source'=>$cfg['source']];
 }
 public function send(Lead $lead): array {
  $check=$this->preflight($lead); if(!$check['ready'])return ['ok'=>false,'preflight'=>$check];
  $cfg=$this->config(); $debts=DB::table('debts as d')->leftJoin('creditors as c','c.id','=','d.creditor_id')->where('d.lead_id',$lead->id)->orderBy('d.id')->get(['d.balance','d.reference','c.name as creditor']);
  $address=trim(implode(' ',array_filter([$lead->house_number,$lead->house_name,$lead->building_number,$lead->address_line_1],fn($v)=>trim((string)$v)!=='')));
  $client=['campaignid'=>$cfg['campaign'],'lead_source'=>$cfg['source'],'title'=>strtolower(trim((string)$lead->title)),'firstname'=>trim((string)$lead->first_name),'middlename'=>trim((string)$lead->middle_name),'lastname'=>trim((string)$lead->last_name),'dob'=>(string)strtotime(substr((string)$lead->dob,0,10).' 12:00:00 UTC'),'phone_mobile'=>trim((string)$lead->phone_number),'email'=>trim((string)$lead->email),'address_1'=>$address,'postcode'=>trim((string)$lead->postcode),'import_reference'=>'JINX-'.$lead->id,'note'=>'Transferred from Jinx lead #'.$lead->id.' by approved Jinx Avondale integration.','lead_generator'=>'Jinx'];
  $r=$this->post('/client/format/json/',$client,$cfg); if(($r['status']??null)!=='success_post'||!filter_var($r['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))throw new RuntimeException('HubSolv client creation was not confirmed. Do not retry until checked.'); $clientId=(int)$r['id'];
  $fields=['clientid'=>(string)$clientId]; foreach($debts as $i=>$d){$fields["debts_data[$i][creditor_name]"]=trim((string)$d->creditor);$fields["debts_data[$i][total]"]=(string)$d->balance;$fields["debts_data[$i][ref]"]=trim((string)$d->reference);} $dr=$this->post('/debt/format/json/',$fields,$cfg); if(!in_array($dr['status']??null,['success_post','success'],true))throw new RuntimeException("HubSolv client {$clientId} was created but debt submission is unconfirmed. Do not retry the client creation."); return ['ok'=>true,'client_id'=>$clientId,'debt_count'=>$debts->count()];
 }
 private function config(): array {$c=['url'=>rtrim((string)env('AVONDALE_HUBSOLV_API_URL'),'/'),'user'=>(string)env('AVONDALE_HUBSOLV_API_USERNAME'),'pass'=>(string)env('AVONDALE_HUBSOLV_API_PASSWORD'),'key'=>(string)env('AVONDALE_HUBSOLV_API_KEY'),'campaign'=>(string)env('AVONDALE_HUBSOLV_CAMPAIGN_ID'),'source'=>(string)env('AVONDALE_HUBSOLV_LEAD_SOURCE')];if($c['url']!=='https://avondale.hubsolv.com/api'||$c['campaign']!=='3'||$c['source']!=='CLEAR_MY_CREDIT'||$c['user']===''||$c['pass']===''||$c['key']==='')throw new RuntimeException('Avondale HubSolv configuration is invalid.');return $c;}
 private function post(string $path,array $fields,array $cfg): array {$r=Http::withBasicAuth($cfg['user'],$cfg['pass'])->asForm()->acceptJson()->connectTimeout(5)->timeout(20)->post($cfg['url'].$path,['HUBSOLV-API-KEY'=>$cfg['key']]+$fields);if(!$r->successful())throw new RuntimeException('HubSolv returned HTTP '.$r->status().'. Outcome not confirmed; do not retry blindly.');$j=$r->json();if(!is_array($j))throw new RuntimeException('HubSolv returned an unreadable response.');return $j;}
}
