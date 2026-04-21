<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\LocalBrowserJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('local-worker:dispatch-test {lead_id?}', function (?int $lead_id = null) {
    $job = LocalBrowserJob::create([
        'lead_id' => $lead_id,
        'job_type' => 'transunion_credit_check',
        'status' => 'queued',
        'payload_json' => [
            'mode' => 'stub',
            'requested_at' => now()->toIso8601String(),
            'notes' => 'Test job created by local-worker:dispatch-test',
        ],
    ]);

    $this->info('Queued local browser job #'.$job->id);
})->purpose('Create one queued local browser test job');
