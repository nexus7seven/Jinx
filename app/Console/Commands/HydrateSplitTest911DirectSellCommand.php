<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HydrateSplitTest911DirectSellCommand extends Command
{
    private const SOURCE_TAG = 'SPLITTEST_911_DIRECT_SELL';

    private const COHORT_LEAD_IDS = [
        '5774346', '7311263', '7225502', '7082674', '6560883', '6555977', '5334891', '7198072',
        '7193789', '6558188', '6061254', '5360692', '5318351', '5141527', '670260', '6618384',
        '6609839', '7531799', '6553120', '5391543', '6689697', '7100222', '7097372', '7098148',
        '7054756', '6604276', '6555052', '6276806', '5975297', '5773290', '5110587', '4877854',
        '5775090', '7100458', '7058668', '7313135', '7355242', '7225503', '7224739', '7083109',
        '7059811', '6557743', '7546813', '4990297', '4886889', '6551899', '142843', '7567732',
    ];

    protected $signature = 'splittest:hydrate-911-direct-sell {--commit : Persist changes. Default mode is dry-run.} {--json : Print machine-readable output.}';

    protected $description = 'Hydrate jinx.leads from fixed vicidial_list cohort (list 911) for direct-sell split test.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $rows = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select(['lead_id', 'list_id', 'first_name', 'last_name', 'phone_number', 'email'])
            ->where('list_id', 911)
            ->whereIn('lead_id', self::COHORT_LEAD_IDS)
            ->get()
            ->keyBy(fn ($row) => (string) $row->lead_id);

        $stats = [
            'dry_run' => ! $commit,
            'source_tag' => self::SOURCE_TAG,
            'total_cohort_count' => count(self::COHORT_LEAD_IDS),
            'found_in_vicidial_list' => $rows->count(),
            'created_jinx_leads' => 0,
            'updated_jinx_leads' => 0,
            'skipped_rows' => 0,
            'rows_missing_phone' => 0,
            'rows_missing_email' => 0,
            'missing_from_vicidial_list' => array_values(array_diff(self::COHORT_LEAD_IDS, $rows->keys()->all())),
            'lead_id_map' => [],
            'warnings' => [],
        ];

        $apply = function () use (&$stats, $rows): void {
            foreach (self::COHORT_LEAD_IDS as $cohortLeadId) {
                $row = $rows->get($cohortLeadId);

                if (! $row) {
                    $stats['skipped_rows']++;
                    continue;
                }

                $phone = $this->cleanString($row->phone_number);
                $email = $this->cleanString($row->email);

                if ($phone === null) {
                    $stats['rows_missing_phone']++;
                }

                if ($email === null) {
                    $stats['rows_missing_email']++;
                }

                $lead = Lead::where('vicidial_lead_id', (string) $row->lead_id)->first();

                if (! $lead) {
                    $lead = new Lead();
                    $lead->vicidial_lead_id = (string) $row->lead_id;
                    $lead->first_name = $this->cleanString($row->first_name);
                    $lead->last_name = $this->cleanString($row->last_name);
                    $lead->phone_number = $phone;
                    $lead->email = $email;
                    $lead->source = self::SOURCE_TAG;
                    $lead->case_notes = self::SOURCE_TAG;
                    $lead->save();
                    $stats['created_jinx_leads']++;
                    $stats['lead_id_map'][] = ['vicidial_lead_id' => (string) $row->lead_id, 'jinx_lead_id' => $lead->id, 'action' => 'created'];
                    continue;
                }

                $changed = false;

                $changed = $this->fillIfEmpty($lead, 'first_name', $row->first_name) || $changed;
                $changed = $this->fillIfEmpty($lead, 'last_name', $row->last_name) || $changed;
                $changed = $this->fillIfEmpty($lead, 'phone_number', $phone) || $changed;
                $changed = $this->fillIfEmpty($lead, 'email', $email) || $changed;
                $changed = $this->fillIfEmpty($lead, 'source', self::SOURCE_TAG) || $changed;

                $changed = $this->appendCaseNoteTag($lead, self::SOURCE_TAG) || $changed;

                if ($changed) {
                    $lead->save();
                    $stats['updated_jinx_leads']++;
                    $action = 'updated';
                } else {
                    $stats['skipped_rows']++;
                    $action = 'unchanged';
                }

                $stats['lead_id_map'][] = ['vicidial_lead_id' => (string) $row->lead_id, 'jinx_lead_id' => $lead->id, 'action' => $action];
            }
        };

        if ($commit) {
            DB::transaction($apply);
        } else {
            DB::beginTransaction();
            try {
                $apply();
                DB::rollBack();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(($commit ? 'COMMIT' : 'DRY-RUN').' '.self::SOURCE_TAG);
            $this->table(['Metric', 'Value'], [
                ['total cohort count', $stats['total_cohort_count']],
                ['found in vicidial_list', $stats['found_in_vicidial_list']],
                ['created Jinx leads', $stats['created_jinx_leads']],
                ['updated Jinx leads', $stats['updated_jinx_leads']],
                ['skipped rows', $stats['skipped_rows']],
                ['rows missing phone', $stats['rows_missing_phone']],
                ['rows missing email', $stats['rows_missing_email']],
            ]);

            foreach ($stats['warnings'] as $warning) {
                $this->warn($warning);
            }

            $this->table(['vicidial_lead_id', 'jinx_lead_id', 'action'], $stats['lead_id_map']);

            if ($stats['missing_from_vicidial_list'] !== []) {
                $this->warn('Missing in vicidial_list: '.implode(',', $stats['missing_from_vicidial_list']));
            }
        }

        return self::SUCCESS;
    }

    private function fillIfEmpty(Lead $lead, string $field, mixed $value): bool
    {
        $incoming = $this->cleanString($value);
        $current = $this->cleanString($lead->{$field});

        if ($current !== null || $incoming === null) {
            return false;
        }

        $lead->{$field} = $incoming;

        return true;
    }

    private function appendCaseNoteTag(Lead $lead, string $tag): bool
    {
        $existing = trim((string) ($lead->case_notes ?? ''));

        if ($existing === '') {
            $lead->case_notes = $tag;

            return true;
        }

        if (str_contains($existing, $tag)) {
            return false;
        }

        $lead->case_notes = $existing.PHP_EOL.'['.$tag.']';

        return true;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
