<?php

namespace App\Services;

use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VicidialLeadService
{
    public function createPartnerLead(Partner $partner, array $payload): int
    {
        $listId = (int) config('services.vicidial.partner_list_id');

        if ($listId <= 0) {
            throw new InvalidArgumentException('VICIDIAL_PARTNER_LIST_ID is not configured.');
        }

        [$phoneCode, $phoneNumber] = $this->normaliseUkPhone($payload['phone_number'] ?? '');

        $now = now();

        return (int) DB::connection('asterisk')
            ->table('vicidial_list')
            ->insertGetId([
                'entry_date' => $now,
                'modify_date' => $now,
                'status' => config('services.vicidial.partner_status', 'NEW'),
                'user' => 'PARTNER',
                'list_id' => $listId,
                'phone_code' => $phoneCode,
                'phone_number' => $phoneNumber,
                'first_name' => $payload['first_name'] ?? '',
                'last_name' => $payload['last_name'] ?? '',
                'address1' => $payload['address_line_1'] ?? '',
                'postal_code' => $payload['postcode'] ?? '',
                'email' => $payload['email'] ?? '',
                'comments' => $payload['comments'] ?? '',
                'source_id' => $payload['source_id'] ?? $partner->name,
                'called_since_last_reset' => 'N',
            ], 'lead_id');
    }

    private function normaliseUkPhone(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            throw new InvalidArgumentException('Phone number is empty.');
        }

        if (str_starts_with($digits, '44') && strlen($digits) > 10) {
            return ['44', substr($digits, 2)];
        }

        if (str_starts_with($digits, '0')) {
            return ['44', substr($digits, 1)];
        }

        return ['44', $digits];
    }
}