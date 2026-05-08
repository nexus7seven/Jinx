<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
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

Artisan::command('local-worker:dispatch-email-code-smoke', function () {
    $job = LocalBrowserJob::create([
        'job_type' => 'email_code_smoke_test',
        'status' => 'queued',
        'payload_json' => [
            'mode' => 'email_code_smoke',
            'requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->info('Queued email code smoke local job #'.$job->id);
})->purpose('Create one queued email code smoke local worker job');

Artisan::command('local-worker:dispatch-transunion-probe {url?}', function (?string $url = null) {
    $job = LocalBrowserJob::create([
        'job_type' => 'transunion_page_probe',
        'status' => 'queued',
        'payload_json' => [
            'url' => $url ?: 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
            'mode' => 'transunion_page_probe',
            'requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->info('Queued transunion page probe local job #'.$job->id);
})->purpose('Create one queued transunion page probe local worker job');

Artisan::command('local-worker:dispatch-transunion-fill-probe {url?}', function (?string $url = null) {
    $job = LocalBrowserJob::create([
        'job_type' => 'transunion_fill_probe',
        'status' => 'queued',
        'payload_json' => [
            'url' => $url ?: 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
            'mode' => 'transunion_fill_probe',
            'agree_terms' => true,
            'personalData' => [
                'title' => 'Mr',
                'first_name' => 'Test',
                'middle_name' => '',
                'last_name' => 'User',
                'dob' => '01/01/1980',
                'phone_number' => '07123456789',
                'postcode' => 'SW1A 1AA',
                'house_number' => '1',
                'address_line_1' => '1 Test Street',
            ],
            'requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->info('Queued transunion fill probe local job #'.$job->id);
})->purpose('Create one queued transunion fill probe local worker job');

Artisan::command('local-worker:dispatch-transunion-submit-probe {url?}', function (?string $url = null) {
    $job = LocalBrowserJob::create([
        'job_type' => 'transunion_submit_probe',
        'status' => 'queued',
        'payload_json' => [
            'url' => $url ?: 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
            'mode' => 'transunion_submit_probe',
            'agree_terms' => true,
            'personalData' => [
                'title' => 'Mr',
                'first_name' => 'Test',
                'middle_name' => '',
                'last_name' => 'User',
                'dob' => '01/01/1980',
                'phone_number' => '07123456789',
                'postcode' => 'SW1A 1AA',
                'house_number' => '1',
                'address_line_1' => '1 Test Street',
            ],
            'requested_at' => now()->toIso8601String(),
        ],
    ]);

    $this->info('Queued transunion submit probe local job #'.$job->id);
})->purpose('Create one queued transunion submit probe local worker job');

Schedule::command('remarketing:scan-inbound-call-responses --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

if (config('remarketing.cbna_scanner_enabled')) {
    Schedule::command('remarketing:scan-cbna --commit --limit=50')
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/remarketing-cbna-scan.log'));
}

if (config('remarketing.linear_executor_enabled')) {
    Schedule::command('remarketing:linear-execute --commit --limit=50')
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/remarketing-linear-execute.log'));
}

if (config('remarketing.call_hopper.enabled')) {
    Schedule::command('remarketing:prepare-call-hopper --commit --window=morning --limit=50')
        ->dailyAt('09:55')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/remarketing-call-hopper.log'));

    Schedule::command('remarketing:prepare-call-hopper --commit --window=evening --limit=50')
        ->dailyAt('17:45')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/remarketing-call-hopper.log'));

    Schedule::command('remarketing:reconcile-call-outcomes --commit --limit=100 --since-minutes=240')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/remarketing-call-reconcile.log'));
}
