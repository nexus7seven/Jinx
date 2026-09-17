<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SetmoreSipAvailabilityService
{
    private const BOOKING_ORIGIN = 'https://insolvencyguidancegroupsips.setmore.com';
    private const SERVICE_ID = 'edde9e2e-3fd5-48c1-8872-039eb18f1832';
    private const BOOKING_PRODUCT_IDS = self::SERVICE_ID . '|ede94323-b941-4f96-9c9d-6b1eefb83478';
    private const BOOKING_PAGE = self::BOOKING_ORIGIN . '/book?step=staff&products=edde9e2e-3fd5-48c1-8872-039eb18f1832%7Cede94323-b941-4f96-9c9d-6b1eefb83478&type=service';
    private const SLOTS_ENDPOINT = 'https://cbphandlers.setmore.com/handlers/graphql?operation=GetSlots';
    private const COMPANY_ID = '3460e9c0-6a79-4247-95ed-8d36a665b14b';
    private const TIME_ZONE = 'Europe/London';
    private const EXCLUDED_NAMES = ['John Bickerton', 'Paul Peak Summers', 'Kevin Roberts'];

    public function availability(int $days = 7): array
    {
        $days = max(1, min($days, 31));
        $directory = $this->staffDirectory();
        $serviceStaffIds = $directory['service_staff_ids'];
        $excluded = array_map('strtolower', self::EXCLUDED_NAMES);
        $staff = array_values(array_filter($directory['staff'], function (array $person) use ($serviceStaffIds, $excluded) {
            return in_array($person['id'], $serviceStaffIds, true)
                && ! in_array(strtolower(trim($person['name'])), $excluded, true);
        }));
        if ($staff === []) {
            throw new RuntimeException('No eligible SIP staff were found in Setmore.');
        }

        $now = CarbonImmutable::now(self::TIME_ZONE);
        $start = $now->startOfDay();
        $end = $start->addDays($days - 1)->endOfDay();
        $rangeEndMs = $end->getTimestamp() * 1000 + 999;
        $slots = [];
        $errors = [];

        foreach ($staff as $person) {
            try {
                foreach ($this->slotsForStaff($person['id'], $start, $end) as $slot) {
                    if (! is_array($slot) || ! isset($slot['ms']) || ! is_numeric($slot['ms'])) {
                        continue;
                    }
                    $when = CarbonImmutable::createFromTimestampMs((int) $slot['ms'], self::TIME_ZONE);
                    if ($when->lt($now) || (int) $slot['ms'] > $rangeEndMs) {
                        continue;
                    }
                    $slots[] = [
                        'staff_id' => $person['id'],
                        'staff_name' => $person['name'],
                        'ms' => (string) $slot['ms'],
                        'iso' => $when->toIso8601String(),
                        'date' => $when->format('Y-m-d'),
                        'date_label' => $when->format('D j M'),
                        'time' => $when->format('H:i'),
                        'display' => $slot['displayDateTime'] ?? $when->format('d/M/Y H:i T'),
                        'booking_url' => self::BOOKING_ORIGIN . '/book?step=time-slot&products=' . rawurlencode(self::BOOKING_PRODUCT_IDS) . '&type=service&staff=' . rawurlencode($person['id']) . '&staffSelected=true',
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
                $errors[] = $person['name'];
            }
        }

        if (count($errors) === count($staff)) {
            throw new RuntimeException('Setmore availability failed for all eligible SIP staff.');
        }
        usort($slots, fn (array $a, array $b) => ((int) $a['ms']) <=> ((int) $b['ms']));

        return [
            'slots' => $slots,
            'staff' => $staff,
            'excluded' => self::EXCLUDED_NAMES,
            'errors' => $errors,
            'partial' => $errors !== [],
            'booking_page' => self::BOOKING_PAGE,
            'service' => '1 Hour Meeting',
            'days' => $days,
            'range_start' => $start->format('Y-m-d'),
            'range_end' => $end->format('Y-m-d'),
            'fetched_at' => $now->toIso8601String(),
        ];
    }

    private function staffDirectory(): array
    {
        $html = Http::timeout(12)->retry(2, 250)->get(self::BOOKING_PAGE)->throw()->body();
        if (! preg_match('/\"buildId\":\"([^\"]+)\"/', $html, $match)) {
            throw new RuntimeException('Could not discover Setmore build ID.');
        }

        $url = self::BOOKING_ORIGIN . '/_next/data/' . $match[1] . '/book.json';
        $response = Http::timeout(12)->retry(2, 250)->withHeaders(['Referer' => self::BOOKING_ORIGIN . '/'])->get($url, [
            'step' => 'staff',
            'products' => self::BOOKING_PRODUCT_IDS,
            'type' => 'service',
        ])->throw();

        $payload = $response->json();
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($payload)) {
            throw new RuntimeException('Setmore booking data was invalid.');
        }

        $pageProps = $payload['pageProps'] ?? [];
        if (! is_array($pageProps)) {
            throw new RuntimeException('Setmore page data was invalid.');
        }
        $company = is_array($pageProps['company'] ?? null) ? $pageProps['company'] : [];
        $staffRows = $pageProps['staff'] ?? $company['staff'] ?? [];
        if (! is_array($staffRows)) {
            throw new RuntimeException('Setmore staff directory was not found.');
        }
        $services = $pageProps['services'] ?? $company['services'] ?? [];
        if (! is_array($services)) {
            throw new RuntimeException('Setmore services were not found.');
        }
        $services = array_values(array_filter($services, 'is_array'));
        $service = collect($services)->firstWhere('id', self::SERVICE_ID);
        if (! is_array($service)) {
            throw new RuntimeException('Setmore SIP service was not found.');
        }

        $serviceStaff = $service['staff'] ?? [];
        if (! is_array($serviceStaff)) {
            $serviceStaff = [];
        }

        $staffRows = array_values(array_filter($staffRows, 'is_array'));
        $serviceStaff = array_values(array_filter($serviceStaff, 'is_array'));

        return [
            'staff' => array_values(array_filter(array_map(fn (array $row) => [
                'id' => (string) ($row['id'] ?? ''),
                'name' => trim((string) ($row['displayName'] ?? $row['name'] ?? '')),
            ], $staffRows), fn (array $row) => $row['id'] !== '' && $row['name'] !== '')),
            'service_staff_ids' => array_values(array_filter(array_map(fn (array $row) => (string) ($row['id'] ?? ''), $serviceStaff))),
        ];
    }

    private function slotsForStaff(string $staffId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = <<<'GRAPHQL'
query GetSlots($companyId: ID!, $durationMins: Int!, $endDateISO: String!, $serviceIds: [ID!]!, $staffId: ID, $startDateISO: String!, $timeZone: String!) {
  slots(where: {companyId: $companyId, durationMins: $durationMins, endDateISO: $endDateISO, serviceIds: $serviceIds, staffId: $staffId, startDateISO: $startDateISO, timeZone: $timeZone}) {
    displayDateTime
    ms
    staffId
    duration
    isVideoEnabled
  }
}
GRAPHQL;

        $response = Http::timeout(15)->retry(2, 250)->withHeaders([
            'Origin' => self::BOOKING_ORIGIN,
            'X-Cbp-Origin' => self::BOOKING_ORIGIN,
        ])->post(self::SLOTS_ENDPOINT, [
            'operationName' => 'GetSlots',
            'variables' => [
                'companyId' => self::COMPANY_ID,
                'durationMins' => 60,
                'endDateISO' => $end->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
                'serviceIds' => [self::SERVICE_ID],
                'staffId' => $staffId,
                'startDateISO' => $start->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
                'timeZone' => self::TIME_ZONE,
            ],
            'query' => $query,
        ])->throw()->json();

        if (! is_array($response)) {
            throw new RuntimeException('Setmore returned an invalid slots response.');
        }
        if (! empty($response['errors'])) {
            throw new RuntimeException('Setmore returned a GraphQL error for staff ' . $staffId);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $slots = $data['slots'] ?? [];
        return is_array($slots) ? $slots : [];
    }
}
