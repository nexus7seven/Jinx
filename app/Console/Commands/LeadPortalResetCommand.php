<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadPortalEmailClick;
use App\Models\LeadPortalProgress;
use App\Models\LeadPortalSnapshot;
use App\Models\LeadPortalToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LeadPortalResetCommand extends Command
{
    protected $signature = 'portal:reset {leadId : Jinx ID or Vicidial lead ID} {--yes}';

    protected $description = 'Admin-only diagnostic: reset portal state for one lead (dry-run unless --yes).';

    public function handle(): int
    {
        $leadId = (string) $this->argument('leadId');
        $lead = Lead::query()
            ->where('vicidial_lead_id', $leadId)
            ->orWhere('id', $leadId)
            ->first();

        if (! $lead) {
            $this->error('Lead not found for given Jinx ID or Vicidial lead ID: '.$leadId);

            return self::FAILURE;
        }

        $tokenCount = LeadPortalToken::query()->where('lead_id', $lead->id)->count();
        $progressExists = LeadPortalProgress::query()->where('lead_id', $lead->id)->exists();
        $snapshotCount = LeadPortalSnapshot::query()->where('lead_id', $lead->id)->count();
        $emailClickCount = LeadPortalEmailClick::query()->where('lead_id', $lead->id)->count();
        $guardFields = [
            'portal_credit_check_started_at',
            'portal_credit_check_completed_at',
            'portal_credit_check_last_run_at',
        ];
        $guardFieldsCurrentlySet = collect($guardFields)
            ->filter(fn (string $field) => ! is_null($lead->{$field}))
            ->values()
            ->all();

        $dryRun = ! (bool) $this->option('yes');
        $payload = [
            'ok' => true,
            'mode' => $dryRun ? 'dry-run' : 'committed',
            'jinx_lead_id' => $lead->id,
            'vicidial_lead_id' => $lead->vicidial_lead_id,
            'tokens_to_revoke' => $tokenCount,
            'progress_row_exists' => $progressExists,
            'snapshots_to_delete' => $snapshotCount,
            'email_clicks_to_delete' => $emailClickCount,
            'portal_guard_fields_to_clear' => $guardFieldsCurrentlySet,
            'notes' => [
                'Lead is not deleted.',
                'Canonical debts are not deleted.',
                'Credit check job logs are not deleted.',
                'Debt documents are not deleted.',
                'Remarketing records are not altered.',
            ],
        ];

        if ($dryRun) {
            $this->warn('DRY RUN ONLY. Re-run with --yes to apply changes.');
            $this->outputPayload($payload);

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($lead, $guardFields): array {
            $now = now();
            $tokensRevoked = LeadPortalToken::query()
                ->where('lead_id', $lead->id)
                ->update([
                    'status' => LeadPortalToken::STATUS_REVOKED,
                    'revoked_at' => $now,
                    'last_used_at' => $now,
                ]);

            $progress = LeadPortalProgress::query()->where('lead_id', $lead->id)->first();
            if ($progress) {
                $progress->current_step = 'welcome';
                $progress->last_completed_step = null;
                $progress->started_at = null;
                $progress->last_seen_at = null;
                $progress->completed_at = null;
                $progress->save();
                $progressReset = 1;
            } else {
                $progressReset = 0;
            }

            $snapshotsDeleted = LeadPortalSnapshot::query()->where('lead_id', $lead->id)->delete();
            $emailClicksDeleted = LeadPortalEmailClick::query()->where('lead_id', $lead->id)->delete();

            foreach ($guardFields as $field) {
                $lead->{$field} = null;
            }
            $lead->save();

            return [
                'tokens_revoked' => $tokensRevoked,
                'progress_reset' => $progressReset,
                'snapshots_deleted' => $snapshotsDeleted,
                'email_clicks_deleted' => $emailClicksDeleted,
                'fields_cleared' => $guardFields,
            ];
        });

        $this->info('Portal state reset committed.');
        $this->outputPayload(array_merge($payload, $result));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outputPayload(array $payload): void
    {
        $this->line('jinx_lead_id: '.$payload['jinx_lead_id']);
        $this->line('vicidial_lead_id: '.($payload['vicidial_lead_id'] ?? 'null'));
        $this->line('mode: '.$payload['mode']);
        $this->line('tokens_to_revoke: '.$payload['tokens_to_revoke']);
        $this->line('progress_row_exists: '.($payload['progress_row_exists'] ? 'yes' : 'no'));
        $this->line('snapshots_to_delete: '.$payload['snapshots_to_delete']);
        $this->line('email_clicks_to_delete: '.$payload['email_clicks_to_delete']);
        $this->line('portal_guard_fields_to_clear: '.implode(', ', $payload['portal_guard_fields_to_clear']));

        if (array_key_exists('tokens_revoked', $payload)) {
            $this->line('tokens_revoked: '.$payload['tokens_revoked']);
            $this->line('progress_reset: '.$payload['progress_reset']);
            $this->line('snapshots_deleted: '.$payload['snapshots_deleted']);
            $this->line('email_clicks_deleted: '.$payload['email_clicks_deleted']);
            $this->line('fields_cleared: '.implode(', ', $payload['fields_cleared']));
        }
    }
}
