<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class VicidialLeadLookupService
{
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
