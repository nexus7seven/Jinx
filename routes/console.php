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

Artisan::command('local-worker:dispatch-browser-smoke {url?}', function (?string $url = null) {
    $job = LocalBrowserJob::create([
        'job_type' => 'browser_smoke_test',
        'status' => 'queued',
        'payload_json' => [
            'url' => $url ?: 'https://example.com',
            'mode' => 'smoke',
            'requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->info('Queued browser smoke local job #'.$job->id);
})->purpose('Create one queued browser smoke local worker job');

Artisan::command('local-worker:dispatch-interactive-smoke', function () {
    $job = LocalBrowserJob::create([
        'job_type' => 'interactive_smoke_test',
        'status' => 'queued',
        'payload_json' => [
            'mode' => 'interactive_smoke',
            'requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->info('Queued interactive smoke local job #'.$job->id);
})->purpose('Create one queued interactive smoke local worker job');
