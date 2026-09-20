<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PostcodeJurisdictionService
{
    public function syncIfNeeded(Lead $lead, DecisionCaseFactService $facts): ?string
    {
        $postcode = $this->normalisePostcode((string) $lead->postcode);
        if ($postcode === '') return null;

        $existing = $facts->leadFacts($lead)['case.jurisdiction'] ?? null;
        $existingValue = trim((string) ($existing['value'] ?? ''));
        $existingSource = (string) ($existing['source_type'] ?? '');

        if ($existingValue !== '' && $existingSource !== 'postcode_lookup') {
            return $existingValue;
        }

        $jurisdiction = $this->lookup($postcode);
        if ($jurisdiction === null) return $existingValue !== '' ? $existingValue : null;

        if ($existingValue !== $jurisdiction || $existingSource !== 'postcode_lookup') {
            $facts->setLeadFact(
                $lead,
                'case.jurisdiction',
                $jurisdiction,
                'postcode_lookup',
                'Derived from the lead postcode using Postcodes.io'
            );
        }

        return $jurisdiction;
    }

    public function lookup(string $postcode): ?string
    {
        $postcode = $this->normalisePostcode($postcode);
        if ($postcode === '') return null;

        return Cache::remember(
            'postcode-jurisdiction:'.Str::upper(str_replace(' ', '', $postcode)),
            now()->addDays(30),
            function () use ($postcode) {
                try {
                    $response = Http::acceptJson()
                        ->connectTimeout(2)
                        ->timeout(4)
                        ->get('https://api.postcodes.io/postcodes/'.rawurlencode($postcode));

                    if (!$response->successful()) return null;

                    $country = trim((string) $response->json('result.country'));
                    return in_array($country, ['England', 'Wales', 'Scotland', 'Northern Ireland'], true)
                        ? $country
                        : null;
                } catch (\Throwable) {
                    return null;
                }
            }
        );
    }

    private function normalisePostcode(string $postcode): string
    {
        $postcode = Str::upper(trim($postcode));
        $postcode = preg_replace('/\s+/', '', $postcode) ?: '';
        if ($postcode === '') return '';

        if (strlen($postcode) > 3) {
            return substr($postcode, 0, -3).' '.substr($postcode, -3);
        }

        return $postcode;
    }
}
