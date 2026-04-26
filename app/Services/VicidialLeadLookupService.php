<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class VicidialLeadLookupService
{
    /**
     * Resolve VICIdial dial context using the same preference order as Deckard:
     * 1) by VICIdial lead_id, 2) by phone_number (most recent lead_id).
     *
     * @return array{lead_id:int,campaign_id:?string}
     */
    public function resolveDialContext(Lead $lead, ?string $nationalDigits = null): array
    {
        $connection = config('services.vicidial.db_connection');
        $vicidialLeadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : 0;

        if ($vicidialLeadId > 0) {
            $row = DB::connection($connection)
                ->table('vicidial_list as vl')
                ->leftJoin('vicidial_lists as vls', 'vls.list_id', '=', 'vl.list_id')
                ->where('vl.lead_id', $vicidialLeadId)
                ->select(['vl.lead_id', 'vls.campaign_id'])
                ->first();

            $campaignId = is_string($row->campaign_id ?? null) ? trim((string) $row->campaign_id) : '';
            if ($row && $campaignId !== '') {
                return [
                    'lead_id' => (int) ($row->lead_id ?? $vicidialLeadId),
                    'campaign_id' => $campaignId,
                ];
            }
        }

        if (is_string($nationalDigits) && $nationalDigits !== '') {
            $row = DB::connection($connection)
                ->table('vicidial_list as vl')
                ->leftJoin('vicidial_lists as vls', 'vls.list_id', '=', 'vl.list_id')
                ->where('vl.phone_number', $nationalDigits)
                ->orderByDesc('vl.lead_id')
                ->select(['vl.lead_id', 'vls.campaign_id'])
                ->first();

            $campaignId = is_string($row->campaign_id ?? null) ? trim((string) $row->campaign_id) : '';
            if ($row && $campaignId !== '') {
                return [
                    'lead_id' => (int) ($row->lead_id ?? 0),
                    'campaign_id' => $campaignId,
                ];
            }
        }

        return [
            'lead_id' => max(0, $vicidialLeadId),
            'campaign_id' => null,
        ];
    }

    public function resolveCampaignIdForLead(Lead $lead): ?string
    {
        $vicidialLeadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : 0;
        if ($vicidialLeadId <= 0) {
            return null;
        }

        $campaignId = DB::connection(config('services.vicidial.db_connection'))
            ->table('vicidial_list')
            ->where('lead_id', $vicidialLeadId)
            ->value('campaign_id');

        if (! is_string($campaignId)) {
            return null;
        }

        $campaignId = trim($campaignId);

        return $campaignId !== '' ? $campaignId : null;
    }
}
