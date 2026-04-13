<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Latest dial / contact time from VICIdial (vicidial_log.call_date) keyed by vicidial_list.lead_id.
 */
class VicidialDialActivityService
{
    /**
     * @return array<int, Carbon> vicidial lead_id => last activity moment
     */
    public function lastDialledAtByVicidialLeadIds(array $vicidialLeadIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vicidialLeadIds))));
        if ($ids === []) {
            return [];
        }

        try {
            $rows = DB::connection('asterisk')
                ->table('vicidial_log')
                ->selectRaw('lead_id, MAX(call_date) as last_call_date')
                ->whereIn('lead_id', $ids)
                ->groupBy('lead_id')
                ->get();
        } catch (Throwable $e) {
            Log::debug('vicidial_log query failed', ['exception' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $lid = (int) $row->lead_id;
            if (! empty($row->last_call_date)) {
                $out[$lid] = Carbon::parse($row->last_call_date);
            }
        }

        return $out;
    }

    /**
     * Sets dynamic attribute last_dialled_at (Carbon|null) on each lead by Jinx lead id.
     */
    public function attachLastDialledToLeads(Collection|EloquentCollection $leads): void
    {
        $vicidialByJinxId = [];
        $vicidialIds = [];
        foreach ($leads as $lead) {
            $vid = $lead->vicidial_lead_id;
            if ($vid !== null && $vid !== '') {
                $intVid = (int) $vid;
                $vicidialIds[] = $intVid;
                $vicidialByJinxId[$lead->id] = $intVid;
            }
        }

        $byVicidial = $this->lastDialledAtByVicidialLeadIds($vicidialIds);

        foreach ($leads as $lead) {
            $v = $vicidialByJinxId[$lead->id] ?? null;
            $at = ($v !== null && isset($byVicidial[$v])) ? $byVicidial[$v] : null;
            $lead->setAttribute('last_dialled_at', $at);
        }
    }

    public function lastDialledAtForLead(Lead $lead): ?Carbon
    {
        $vid = $lead->vicidial_lead_id;
        if ($vid === null || $vid === '') {
            return null;
        }

        $map = $this->lastDialledAtByVicidialLeadIds([(int) $vid]);

        return $map[(int) $vid] ?? null;
    }
}
