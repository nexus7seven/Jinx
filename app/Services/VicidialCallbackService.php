<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VicidialCallbackService
{
    public function schedule(Lead $lead, Carbon $when, string $comments = ''): array
    {
        $connection = (string) config('services.vicidial.db_connection', 'asterisk');
        $leadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : 0;
        if ($leadId <= 0) throw new RuntimeException('This Jinx case is not linked to a VICIdial lead.');

        return DB::connection($connection)->transaction(function () use ($connection, $leadId, $when, $comments, $lead) {
            $diallerLead = DB::connection($connection)->table('vicidial_list')->where('lead_id', $leadId)->lockForUpdate()->first();
            if (! $diallerLead) throw new RuntimeException('VICIdial lead not found.');

            DB::connection($connection)->table('vicidial_callbacks')->where('lead_id', $leadId)->whereIn('status', ['ACTIVE', 'LIVE'])->update(['status' => 'INACTIVE']);
            $callbackId = DB::connection($connection)->table('vicidial_callbacks')->insertGetId([
                'lead_id' => $leadId,
                'list_id' => $diallerLead->list_id,
                'campaign_id' => filled($diallerLead->campaign_id ?? null) ? $diallerLead->campaign_id : 'MAIN',
                'status' => 'ACTIVE',
                'entry_time' => now(),
                'callback_time' => $when->format('Y-m-d H:i:s'),
                'user' => (string) config('services.vicidial.agent_user', '6666'),
                'recipient' => 'USERONLY',
                'comments' => mb_substr(trim($comments), 0, 255),
                'lead_status' => 'CALLBK',
            ]);
            DB::connection($connection)->table('vicidial_list')->where('lead_id', $leadId)->update(['status' => 'CBHOLD']);
            DB::connection($connection)->table('vicidial_hopper')->where('lead_id', $leadId)->delete();
            if ($lead->wip_status !== 'WIP') $lead->update(['wip_status' => 'WIP']);

            return ['callback_id' => (int) $callbackId, 'vicidial_lead_id' => $leadId, 'callback_time' => $when->toDateTimeString(), 'comments' => mb_substr(trim($comments), 0, 255)];
        });
    }

    public function activeForJinxLeads(): array
    {
        $connection = (string) config('services.vicidial.db_connection', 'asterisk');
        $rows = DB::connection($connection)->table('vicidial_callbacks as c')
            ->join('vicidial_list as v', 'v.lead_id', '=', 'c.lead_id')
            ->whereIn('c.status', ['ACTIVE', 'LIVE'])->orderBy('c.callback_time')
            ->get(['c.callback_id','c.lead_id','c.callback_time','c.comments','c.status','v.status as lead_status']);
        $leads = Lead::query()->whereIn('vicidial_lead_id', $rows->pluck('lead_id')->all())->get()->keyBy(fn (Lead $l) => (int) $l->vicidial_lead_id);
        return $rows->map(function ($row) use ($leads) {
            $lead = $leads->get((int) $row->lead_id); if (! $lead) return null;
            $when = Carbon::parse($row->callback_time);
            return ['callback_id'=>(int)$row->callback_id,'lead_id'=>$lead->id,'vicidial_lead_id'=>(int)$row->lead_id,'lead_name'=>trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: 'Lead #'.$lead->id,'callback_time'=>$when->toIso8601String(),'callback_display'=>$when->format('D j M, H:i'),'comments'=>(string)($row->comments ?? ''),'due'=>$when->lte(now()),'overdue'=>$when->lt(now()->subMinutes(5))];
        })->filter()->values()->all();
    }
}
