<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadPortalLinkService;
use Illuminate\Console\Command;

class ExportSplitTest911DirectSellLinksCommand extends Command
{
    private const SOURCE_TAG = 'SPLITTEST_911_DIRECT_SELL';

    private const CSV_RELATIVE_PATH = 'app/splittest_911_direct_sell_export.csv';

    protected $signature = 'splittest:export-911-direct-sell-links {--json}';

    protected $description = 'Export SPLITTEST_911_DIRECT_SELL lead portal links and message bodies to CSV.';

    public function __construct(
        private readonly LeadPortalLinkService $leadPortalLinkService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $leads = Lead::query()
            ->where('source', self::SOURCE_TAG)
            ->orderBy('id')
            ->get();

        $csvPath = storage_path(self::CSV_RELATIVE_PATH);
        $directory = dirname($csvPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Failed to create export directory: '.$directory);

            return self::FAILURE;
        }

        $handle = fopen($csvPath, 'w');

        if ($handle === false) {
            $this->error('Failed to open CSV file for writing: '.$csvPath);

            return self::FAILURE;
        }

        $headers = [
            'jinx_lead_id',
            'vicidial_lead_id',
            'first_name',
            'last_name',
            'phone_number',
            'email',
            'portal_url',
            'sms_body',
            'whatsapp_body',
        ];

        fputcsv($handle, $headers);

        $summary = [
            'leads_found' => $leads->count(),
            'rows_exported' => 0,
            'rows_missing_phone' => 0,
            'rows_missing_email' => 0,
            'csv_path' => $csvPath,
            'errors' => [],
        ];

        foreach ($leads as $lead) {
            $phone = $this->cleanString($lead->phone_number);
            $email = $this->cleanString($lead->email);

            if ($phone === null) {
                $summary['rows_missing_phone']++;
            }

            if ($email === null) {
                $summary['rows_missing_email']++;
            }

            try {
                $generated = $this->leadPortalLinkService->generateForLead($lead);
                $portalUrl = (string) ($generated['portal_url'] ?? '');

                $firstName = $this->cleanString($lead->first_name) ?? 'there';
                $smsBody = $this->buildSmsBody($firstName, $portalUrl);
                $whatsAppBody = $this->buildWhatsAppBody($firstName, $portalUrl);

                fputcsv($handle, [
                    $lead->id,
                    $lead->vicidial_lead_id,
                    $this->cleanString($lead->first_name),
                    $this->cleanString($lead->last_name),
                    $phone,
                    $email,
                    $portalUrl,
                    $smsBody,
                    $whatsAppBody,
                ]);

                $summary['rows_exported']++;
            } catch (\Throwable $e) {
                $summary['errors'][] = [
                    'jinx_lead_id' => $lead->id,
                    'vicidial_lead_id' => $lead->vicidial_lead_id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        fclose($handle);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['metric', 'value'], [
            ['leads found', $summary['leads_found']],
            ['rows exported', $summary['rows_exported']],
            ['rows missing phone', $summary['rows_missing_phone']],
            ['rows missing email', $summary['rows_missing_email']],
            ['csv path', $summary['csv_path']],
            ['errors', count($summary['errors'])],
        ]);

        if ($summary['errors'] !== []) {
            $this->table(['jinx_lead_id', 'vicidial_lead_id', 'message'], $summary['errors']);
        }

        return self::SUCCESS;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function buildSmsBody(string $firstName, string $portalUrl): string
    {
        return "Hi {$firstName}, you previously asked us to contact you about debt help.\n\n"
            ."You can complete a free secure credit check here to see what debts you have, this doesn't affect your credit file\n\n"
            ."{$portalUrl}\n\n"
            ."We could write off up to 85% of your debt and stop ALL enforcement straight away\n\n"
            .'You can message us here http://whatsapp.clearmycredit.co.uk/'
            ."\n\nClear My Credit";
    }

    private function buildWhatsAppBody(string $firstName, string $portalUrl): string
    {
        return "Hi {$firstName}, you previously asked us to contact you about debt help.\n\n"
            ."You can complete a free secure credit check here to see what debts you have, this doesn't affect your credit file\n\n"
            ."{$portalUrl}\n\n"
            ."We could write off up to 85% of your debt and stop ALL enforcement straight away\n\n"
            .'Clear My Credit';
    }
}
