<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;

class SendSplitTest911DirectSellSmsCommand extends Command
{
    protected $signature = 'splittest:send-911-direct-sell-sms
        {--commit : Send real SMS messages (default is dry-run)}
        {--limit= : Optional max eligible rows to process}
        {--only-lead-id= : Optional single Jinx lead id filter}
        {--json : Output JSON payload}';

    protected $description = 'One-off sender for SPLITTEST_911_DIRECT_SELL SMS from exported CSV (dry-run by default).';

    public function __construct(private readonly SmsService $smsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $csvPath = storage_path('app/splittest_911_direct_sell_export.csv');
        $commit = (bool) $this->option('commit');
        $dryRun = ! $commit;
        $asJson = (bool) $this->option('json');
        $onlyLeadId = trim((string) ($this->option('only-lead-id') ?? ''));
        $limitOption = $this->option('limit');
        $limit = null;

        if ($limitOption !== null && $limitOption !== '') {
            $limit = max(1, (int) $limitOption);
        }

        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            $this->error('CSV missing or unreadable: '.$csvPath);

            return self::FAILURE;
        }

        if ($dryRun && ! $asJson) {
            $this->warn('DRY-RUN MODE: No SMS will be sent unless you pass --commit.');
        }

        $summary = [
            'dry_run' => $dryRun,
            'rows_read' => 0,
            'rows_eligible' => 0,
            'rows_sent' => 0,
            'rows_skipped' => 0,
            'errors' => 0,
        ];
        $rows = [];

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->error('Unable to open CSV: '.$csvPath);

            return self::FAILURE;
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                $this->error('CSV header row is missing.');

                return self::FAILURE;
            }

            $headerMap = $this->buildHeaderMap($header);
            foreach (['jinx_lead_id', 'vicidial_lead_id', 'first_name', 'phone_number', 'portal_url', 'sms_body'] as $required) {
                if (! array_key_exists($required, $headerMap)) {
                    $this->error('CSV missing required column: '.$required);

                    return self::FAILURE;
                }
            }

            $processedEligible = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $summary['rows_read']++;

                $row = $this->mapRow($headerMap, $data);
                $jinxLeadId = trim((string) ($row['jinx_lead_id'] ?? ''));
                $vicidialLeadId = trim((string) ($row['vicidial_lead_id'] ?? ''));
                $phone = trim((string) ($row['phone_number'] ?? ''));
                $smsBody = trim((string) ($row['sms_body'] ?? ''));

                if ($onlyLeadId !== '' && $jinxLeadId !== $onlyLeadId) {
                    $summary['rows_skipped']++;
                    continue;
                }

                if ($phone === '') {
                    $summary['rows_skipped']++;
                    $rows[] = [
                        'jinx_lead_id' => $jinxLeadId,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'normalized_phone' => null,
                        'action' => 'skipped_empty_phone',
                    ];
                    continue;
                }

                if ($limit !== null && $processedEligible >= $limit) {
                    $summary['rows_skipped']++;
                    continue;
                }

                $summary['rows_eligible']++;
                $processedEligible++;

                $normalizedPhone = $this->normalizeUkMobile($phone);
                if ($normalizedPhone === null) {
                    $summary['rows_skipped']++;
                    $rows[] = [
                        'jinx_lead_id' => $jinxLeadId,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'normalized_phone' => null,
                        'action' => 'skipped_invalid_phone',
                    ];
                    continue;
                }

                if ($smsBody === '') {
                    $summary['rows_skipped']++;
                    $rows[] = [
                        'jinx_lead_id' => $jinxLeadId,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'normalized_phone' => $normalizedPhone,
                        'action' => 'skipped_empty_sms_body',
                    ];
                    continue;
                }

                if ($dryRun) {
                    $rows[] = [
                        'jinx_lead_id' => $jinxLeadId,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'normalized_phone' => $normalizedPhone,
                        'action' => 'would_send',
                    ];
                    continue;
                }

                $sent = $this->smsService->sendSms($normalizedPhone, $smsBody);
                if ($sent) {
                    $summary['rows_sent']++;
                    $rows[] = [
                        'jinx_lead_id' => $jinxLeadId,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'normalized_phone' => $normalizedPhone,
                        'action' => 'sent',
                    ];
                } else {
                    $summary['errors']++;
                    $rows[] = [
                        'jinx_lead_id' => $jinxLeadId,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'normalized_phone' => $normalizedPhone,
                        'action' => 'error: '.($this->smsService->lastError() ?? 'unknown'),
                    ];
                }
            }
        } finally {
            fclose($handle);
        }

        $payload = [
            'dry_run' => $summary['dry_run'],
            'rows_read' => $summary['rows_read'],
            'rows_eligible' => $summary['rows_eligible'],
            'rows_sent' => $summary['rows_sent'],
            'rows_skipped' => $summary['rows_skipped'],
            'errors' => $summary['errors'],
            'rows' => $rows,
        ];

        if ($asJson) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('dry_run: '.($payload['dry_run'] ? 'true' : 'false'));
        $this->line('rows_read: '.$payload['rows_read']);
        $this->line('rows_eligible: '.$payload['rows_eligible']);
        $this->line('rows_sent: '.$payload['rows_sent']);
        $this->line('rows_skipped: '.$payload['rows_skipped']);
        $this->line('errors: '.$payload['errors']);

        foreach ($rows as $row) {
            $this->line(str_repeat('-', 60));
            $this->line('jinx_lead_id: '.($row['jinx_lead_id'] !== '' ? $row['jinx_lead_id'] : '-'));
            $this->line('vicidial_lead_id: '.($row['vicidial_lead_id'] !== '' ? $row['vicidial_lead_id'] : '-'));
            $this->line('normalized_phone: '.($row['normalized_phone'] ?? '-'));
            $this->line('action: '.$row['action']);
        }

        return self::SUCCESS;
    }

    private function buildHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $map[trim((string) $name)] = $index;
        }

        return $map;
    }

    private function mapRow(array $headerMap, array $data): array
    {
        $row = [];
        foreach ($headerMap as $name => $index) {
            $row[$name] = isset($data[$index]) ? (string) $data[$index] : '';
        }

        return $row;
    }

    private function normalizeUkMobile(string $phone): ?string
    {
        $trimmed = trim($phone);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return $trimmed;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '+44'.substr($digits, 1);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '7')) {
            return '+44'.$digits;
        }

        return null;
    }
}
