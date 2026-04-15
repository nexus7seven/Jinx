<?php

namespace App\Services;

use App\Models\Partner;
use App\Support\VicidialDialPhone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VicidialLeadService
{
    /** VICIdial vicidial_list.list_id for public website submissions (fixed). */
    private const WEBSITE_LIST_ID = 385173;

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

    /**
     * Public website lead (e.g. Clear My Credit) — same vicidial_list columns as partner flow.
     */
    public function createWebsiteLead(array $payload): int
    {
        [$phoneCode, $phoneNumber] = $this->normaliseUkPhone($payload['phone_number'] ?? '');

        $now = now();

        return (int) DB::connection('asterisk')
            ->table('vicidial_list')
            ->insertGetId([
                'entry_date' => $now,
                'modify_date' => $now,
                'status' => config('services.vicidial.website_status', 'NEW'),
                'user' => 'WEBSITE',
                'list_id' => self::WEBSITE_LIST_ID,
                'phone_code' => $phoneCode,
                'phone_number' => $phoneNumber,
                'first_name' => $payload['first_name'] ?? '',
                'last_name' => $payload['last_name'] ?? '',
                'address1' => '',
                'postal_code' => '',
                'email' => $payload['email'] ?? '',
                'comments' => $payload['comments'] ?? '',
                'source_id' => config('services.vicidial.website_source_id', 'WEBSITE-CLEARMYCREDIT'),
                'called_since_last_reset' => 'N',
            ], 'lead_id');
    }

    /**
     * UK numbers for vicidial_list: phone_code 44 + national digits (no leading 0).
     * Logic matches App\Support\VicidialDialPhone (and deckard_normalize_uk_national).
     */
    private function normaliseUkPhone(string $raw): array
    {
        $national = VicidialDialPhone::nationalDigits($raw);

        if ($national === null || $national === '') {
            throw new InvalidArgumentException('Phone number is empty.');
        }

        return ['44', $national];
    }
}