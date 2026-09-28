<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PostcodeJurisdictionService
{
    /**
     * @return array{annual_listed: float, annual_after_discount: float, monthly: int, single_person_discount_applied: bool}|null
     */
    public function lookupCouncilTax(Lead $lead, ?int $establishedCountingAdults = null): ?array
    {
        $postcode = Str::upper(preg_replace('/\s+/', '', trim((string) $lead->postcode)) ?: '');
        if (! preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode)) {
            return null;
        }

        $needles = $this->addressNeedles($lead);
        if ($needles === []) {
            return null;
        }

        try {
            $response = Http::accept('text/html')
                ->connectTimeout(3)
                ->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->get('https://www.mycounciltax.org.uk/results?postcode='.rawurlencode($postcode));

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > 2_000_000) {
                return null;
            }

            $matches = array_values(array_filter(
                $this->councilTaxRows($body),
                fn (array $row) => $this->addressMatches($row['address'], $needles)
            ));
            if (count($matches) !== 1) {
                return null;
            }

            $annual = $matches[0]['annual'];
            $discount = $establishedCountingAdults === 1;
            $afterDiscount = round($annual * ($discount ? 0.75 : 1.0), 2);

            return [
                'annual_listed' => $annual,
                'annual_after_discount' => $afterDiscount,
                'monthly' => (int) ceil($afterDiscount / 12),
                'single_person_discount_applied' => $discount,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

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

    /**
     * @return array<int, string>
     */
    private function addressNeedles(Lead $lead): array
    {
        $line = $this->normaliseAddress((string) $lead->address_line_1);
        if ($line === '') {
            return [];
        }

        $needles = [];
        foreach (['house_number', 'building_number', 'house_name'] as $field) {
            $identifier = $this->normaliseAddress((string) $lead->{$field});
            if ($identifier === '') {
                continue;
            }
            $needles[] = str_starts_with($line, $identifier.' ')
                || $line === $identifier
                ? $line
                : $identifier.' '.$line;
        }

        if ($needles === [] && preg_match('/\d/u', $line)) {
            $needles[] = $line;
        }

        return array_values(array_unique($needles));
    }

    /**
     * @return array<int, array{address: string, annual: float}>
     */
    private function councilTaxRows(string $html): array
    {
        if (! class_exists(\DOMDocument::class)) {
            return [];
        }

        $prior = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        if (! $loaded) {
            return [];
        }

        $rows = [];
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//table//tr') ?: [] as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells === false || $cells->length !== 3) {
                continue;
            }

            $address = trim((string) $cells->item(0)?->textContent);
            $band = Str::upper(trim((string) $cells->item(1)?->textContent));
            $amount = trim((string) $cells->item(2)?->textContent);
            if (
                $address === ''
                || ! preg_match('/^[A-I]$/', $band)
                || ! preg_match('/^£\s*(\d+(?:,\d{3})*)(?:\.(\d{1,2}))?$/u', $amount, $match)
            ) {
                continue;
            }

            $annual = (float) (str_replace(',', '', $match[1]).'.'.($match[2] ?? '0'));
            if ($annual <= 0 || $annual > 100_000) {
                continue;
            }
            $rows[] = ['address' => $address, 'annual' => $annual];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function addressMatches(string $candidate, array $needles): bool
    {
        $candidate = $this->normaliseAddress($candidate);
        foreach ($needles as $needle) {
            if ($candidate === $needle || str_starts_with($candidate, $needle.' ')) {
                return true;
            }
        }

        return false;
    }

    private function normaliseAddress(string $value): string
    {
        $value = Str::upper(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?: '';

        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }
}
