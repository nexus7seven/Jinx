<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SetmoreSipAvailabilityService
{
    private const BOOKING_ORIGIN = 'https://insolvencyguidancegroupsips.setmore.com';
    private const BOOKING_PAGE = self::BOOKING_ORIGIN . '/book?step=staff&products=edde9e2e-3fd5-48c1-8872-039eb18f1832%7Cede94323-b941-4f96-9c9d-6b1eefb83478&type=service';
    private const SLOTS_ENDPOINT = 'https://cbphandlers.setmore.com/handlers/graphql?operation=GetSlots';
    private const SERVICE_ID = 'edde9e2e-3fd5-48c1-8872-039eb18f1832';
    private const COMPANY_ID = '3460e9c0-6a79-4247-95ed-8d36a665b14b';
    private const TIME_ZONE = 'Europe/London';
    private const EXCLUDED_NAMES = ['John Bickerton', 'Paul Peak Summers', 'Kevin Roberts'];

    public function availability(int $days = 21): array
    {
        $days = max(1, min($days, 62));
        $directory = $this->staffDirectory();
        $serviceStaffIds = $directory['service_staff_ids'];
        $staff = array_values(array_filter($directory['staff'], function (array $person) use ($serviceStaffIds) {
            return in_array($person['id'], $serviceStaffIds, true)
                && ! in_array($person['name'], self::EXCLUDED_NAMES, true);
        }));

        $start = CarbonImmutable::now(self::TIME_ZONE)->startOfDay();
        $end = $start->addDays($days - 1)->endOfDay();
        $slots = [];
        $errors = [];

        foreach ($staff as $person) {
            try {
                foreach ($this->slotsForStaff($person['id'], $start, $end) as $slot) {
                    $when = CarbonImmutable::createFromTimestampMs((int) $slot['ms'], self::TIME_ZONE);
                    if ($when->lt(CarbonImmutable::now(self::TIME_ZONE))) {
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
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
                $errors[] = $person['name'];
            }
        }

        usort($slots, fn (array $a, array $b) => ((int) $a['ms']) <=> ((int) $b['ms']));

        return [
            'slots' => $slots,
            'staff' => $staff,
            'excluded' => self::EXCLUDED_NAMES,
            'errors' => $errors,
            'booking_page' => self::BOOKING_PAGE,
            'fetched_at' => CarbonImmutable::now(self::TIME_ZONE)->toIso8601String(),
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
            'products' => self::SERVICE_ID . '|ede94323-b941-4f96-9c9d-6b1eefb83478',
            'type' => 'service',
        ])->throw();

        $payload = $response->json();
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        $pageProps = $payload['pageProps'] ?? [];
        $staffRows = $pageProps['staff'] ?? $pageProps['company']['staff'] ?? [];
        $services = $pageProps['services'] ?? $pageProps['company']['services'] ?? [];
        $service = collect($services)->firstWhere('id', self::SERVICE_ID);
        if (! $service) {
            throw new RuntimeException('Setmore SIP service was not found.');
        }

        return [
            'staff' => array_map(fn (array $row) => ['id' => $row['id'], 'name' => $row['displayName']], $staffRows),
            'service_staff_ids' => array_values(array_map(fn (array $row) => $row['id'], $service['staff'] ?? [])),
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

        if (! empty($response['errors'])) {
            throw new RuntimeException('Setmore returned a GraphQL error for staff ' . $staffId);
        }

        return $response['data']['slots'] ?? [];
    }
}
