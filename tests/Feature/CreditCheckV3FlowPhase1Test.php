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
}
