<?php

namespace Tests\Feature;

use App\Models\CreditCheckJobLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreditCheckV3FlowPhase1Test extends TestCase
{
    use RefreshDatabase;

    public function test_agent_credit_check_v3_run_route_still_works(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');

        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'job-123',
                'queued' => false,
            ], 200),
        ]);

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'cc-v3-phase1-'.uniqid('', true),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('leads.credit-check-v3.run', ['lead' => $lead->id]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'jobId' => 'job-123',
            ])
            ->assertJsonStructure(['credit_check_job_log_id']);

        $log = CreditCheckJobLog::query()->where('lead_id', $lead->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(CreditCheckJobLog::STATUS_RUNNING, $log->status);
        $this->assertSame('job-123', $log->external_job_id);
    }

    public function test_agent_credit_check_v3_submit_answers_route_still_works(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');

        Http::fake([
            'http://listener.test/jobs/job-answers/answers' => Http::response([
                'ok' => true,
                'message' => 'Answers accepted',
            ], 200),
        ]);

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'cc-v3-phase1-answers-'.uniqid('', true),
        ]);
        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'job-answers',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Awaiting verification',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('leads.credit-check-v3.answers', ['jobId' => 'job-answers']), [
                'answers' => [
                    ['id' => 'q1', 'index' => 0, 'value' => 'Blue'],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Answers accepted',
            ]);
    }

    public function test_agent_credit_check_v3_import_route_still_works(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'cc-v3-phase1-import-'.uniqid('', true),
        ]);

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'job-import-1',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now(),
        ]);

        Http::fake([
            'http://listener.test/jobs/job-import-1/state' => Http::response([
                'activeJob' => [
                    'leadId' => $lead->id,
                ],
            ], 200),
            'http://listener.test/jobs/job-import-1/report-data' => Http::response([
                'payload' => ['debts' => [], 'county_court_judgments' => []],
            ], 200),
            'http://listener.test/jobs/job-import-1/pdf-state' => Http::response([
                'payload' => ['status' => 'moved_primary'],
            ], 200),
            'http://listener.test/jobs/job-import-1/status' => Http::response(['ok' => true], 200),
            'http://listener.test/jobs/job-import-1/complete' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('leads.credit-check-v3.import', [
                'lead' => $lead->id,
                'jobId' => 'job-import-1',
            ]))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'imported' => [
                    'total' => 0,
                ],
            ]);
    }
}
