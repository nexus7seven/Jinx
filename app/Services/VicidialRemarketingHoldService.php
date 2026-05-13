<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VicidialRemarketingHoldService
{
    public const CALLBACK_STATUSES = ['CALLBK', 'CBHOLD'];

    public function parkLeadInHoldingListPreservingCallbacks(int $vicidialLeadId, int|string $holdingListId): array
    {
        $connection = (string) config('services.vicidial.db_connection', 'asterisk');
        $holdingListIdValue = (int) (is_string($holdingListId) ? trim($holdingListId) : $holdingListId);

        $before = DB::connection($connection)
            ->table('vicidial_list')
            ->where('lead_id', $vicidialLeadId)
            ->first(['status', 'list_id']);

        if ($before === null) {
            return ['handled' => false, 'updated' => false, 'affected_rows' => 0, 'preserved_callback_status' => false, 'old_status' => null, 'list_id_before' => null, 'list_id_after' => null];
        }

        $oldStatus = (string) ($before->status ?? '');
        $listIdBefore = isset($before->list_id) ? (string) $before->list_id : null;

        $affectedRows = DB::connection($connection)
            ->table('vicidial_list')
            ->where('lead_id', $vicidialLeadId)
            ->update([
                'list_id' => $holdingListIdValue,
                'status' => DB::raw("CASE WHEN status IN ('CALLBK', 'CBHOLD') THEN status ELSE 'HOLD' END"),
            ]);

        $preserved = in_array($oldStatus, self::CALLBACK_STATUSES, true);
        $listIdAfter = (string) $holdingListIdValue;

        if ($preserved) {
            Log::info('preserved_callback_status', [
                'lead_id' => $vicidialLeadId,
                'old_status' => $oldStatus,
                'list_id_before' => $listIdBefore,
                'list_id_after' => $listIdAfter,
            ]);
        }

        return [
            'handled' => true,
            'updated' => $affectedRows > 0,
            'affected_rows' => $affectedRows,
            'preserved_callback_status' => $preserved,
            'old_status' => $oldStatus,
            'list_id_before' => $listIdBefore,
            'list_id_after' => $listIdAfter,
        ];
    }
}
