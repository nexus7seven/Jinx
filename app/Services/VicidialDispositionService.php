<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Latest dial disposition from VICIdial (vicidial_log, with vicidial_list fallback).
 */
class VicidialDispositionService
{
    /**
     * Latest call disposition status for the lead, or current list status if no log row.
     */
    public function getLatestStatusForLead(Lead $lead): ?string
    {
        $vicidialLeadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : null;
        if ($vicidialLeadId === null || $vicidialLeadId <= 0) {
            return null;
        }

        $connection = config('services.vicidial.db_connection');
        if (! is_string($connection) || $connection === '') {
            return null;
        }

        try {
            $logStatus = DB::connection($connection)
                ->table('vicidial_log')
                ->where('lead_id', $vicidialLeadId)
                ->orderByDesc('call_date')
                ->value('status');

            if (is_string($logStatus)) {
                $logStatus = trim($logStatus);
                if ($logStatus !== '') {
                    return $logStatus;
                }
            }

            $listStatus = DB::connection($connection)
                ->table('vicidial_list')
                ->where('lead_id', $vicidialLeadId)
                ->value('status');

            if (is_string($listStatus)) {
                $listStatus = trim($listStatus);
                if ($listStatus !== '') {
                    return $listStatus;
                }
            }
        } catch (Throwable $e) {
            Log::debug('vicidial disposition lookup failed', ['exception' => $e->getMessage()]);
        }

        return null;
    }
}
