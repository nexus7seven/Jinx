<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class VicidialListService
{
    /**
     * Move a VICIdial lead to another list by updating `vicidial_list.list_id`.
     *
     * @return bool True if a row was updated, false otherwise.
     */
    public function moveLeadToList(int $vicidialLeadId, int|string $listId): bool
    {
        if ($vicidialLeadId <= 0) {
            return false;
        }

        $connection = config('services.vicidial.db_connection');
        if (! is_string($connection) || $connection === '') {
            return false;
        }

        if (is_int($listId)) {
            $listIdValue = $listId;
        } else {
            $trimmed = trim((string) $listId);
            if ($trimmed === '' || ! ctype_digit($trimmed)) {
                return false;
            }
            $listIdValue = (int) $trimmed;
        }

        $affected = DB::connection($connection)
            ->table('vicidial_list')
            ->where('lead_id', $vicidialLeadId)
            ->update(['list_id' => $listIdValue]);

        return $affected > 0;
    }
}
