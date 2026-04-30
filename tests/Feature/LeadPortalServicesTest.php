<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadPortalEmailClick;
use App\Models\LeadPortalProgress;
use App\Models\LeadPortalSnapshot;
use App\Models\LeadPortalToken;
use App\Services\LeadPortalLinkService;
use App\Services\LeadPortalProgressService;
use App\Services\LeadPortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeadPortalServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_token_stores_hash_only(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalTokenService::class);

        $issued = $service->issueForLead($lead, '127.0.0.1');
        $token = $issued['portal_token']->fresh();

        $this->assertSame(64, strlen($issued['token']));
        $this->assertFalse($issued['reused']);
        $this->assertSame(LeadPortalToken::STATUS_PENDING, $token->status);
        $this->assertSame(hash('sha256', $issued['token']), $token->token_hash);
        $this->assertNotSame($issued['token'], $token->token_hash);
        $this->assertSame('127.0.0.1', $token->created_ip);
        $this->assertDatabaseMissing('lead_portal_tokens', [
            'token_hash' => $issued['token'],
        ]);
    }

    public function test_issuing_new_token_revokes_existing_usable_token_because_raw_value_cannot_be_recovered(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalTokenService::class);

        $first = $service->issueForLead($lead);
        $second = $service->issueForLead($lead);

        $firstToken = $first['portal_token']->fresh();
        $secondToken = $second['portal_token']->fresh();

        $this->assertSame(LeadPortalToken::STATUS_REVOKED, $firstToken->status);
        $this->assertNotNull($firstToken->revoked_at);
        $this->assertSame(LeadPortalToken::STATUS_PENDING, $secondToken->status);
        $this->assertNotSame($first['token'], $second['token']);
    }

    public function test_resolving_pending_token_activates_it_and_sets_thirty_day_expiry(): void
    {
        Carbon::setTestNow('2026-04-29 09:00:00');

        try {
            $lead = $this->makeLead();
            $service = app(LeadPortalTokenService::class);
            $issued = $service->issueForLead($lead);

            $resolved = $service->resolveRawToken($issued['token'], '192.168.1.1');

            $this->assertNotNull($resolved);
            $this->assertTrue($resolved->relationLoaded('lead'));
            $this->assertSame($lead->id, $resolved->lead->id);
            $this->assertSame(LeadPortalToken::STATUS_ACTIVE, $resolved->status);
            $this->assertSame('2026-04-29 09:00:00', $resolved->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-29 09:00:00', $resolved->expires_at?->format('Y-m-d H:i:s'));
            $this->assertSame('192.168.1.1', $resolved->last_used_ip);
            $this->assertSame('2026-04-29 09:00:00', $resolved->last_used_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_resolving_active_unexpired_token_works(): void
    {
        Carbon::setTestNow('2026-04-29 09:00:00');

        try {
            $lead = $this->makeLead();
            $service = app(LeadPortalTokenService::class);
            $issued = $service->issueForLead($lead);
            $service->resolveRawToken($issued['token'], '10.0.0.1');

            Carbon::setTestNow('2026-05-01 12:00:00');

            $resolved = $service->resolveRawToken($issued['token'], '10.0.0.2');

            $this->assertNotNull($resolved);
            $this->assertSame(LeadPortalToken::STATUS_ACTIVE, $resolved->status);
            $this->assertSame('2026-04-29 09:00:00', $resolved->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-29 09:00:00', $resolved->expires_at?->format('Y-m-d H:i:s'));
            $this->assertSame('10.0.0.2', $resolved->last_used_ip);
            $this->assertSame('2026-05-01 12:00:00', $resolved->last_used_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_resolving_revoked_completed_and_expired_tokens_fails(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalTokenService::class);

        $revoked = LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('revoked-token'),
            'status' => LeadPortalToken::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $completed = LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('completed-token'),
            'status' => LeadPortalToken::STATUS_COMPLETED,
            'completed_at' => now(),
            'revoked_at' => now(),
        ]);

        $expired = LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertNull($service->resolveRawToken('revoked-token'));
        $this->assertNull($service->resolveRawToken('completed-token'));
        $this->assertNull($service->resolveRawToken('expired-token'));
        $this->assertSame(LeadPortalToken::STATUS_EXPIRED, $expired->fresh()->status);
    }

    public function test_completing_token_revokes_it(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalTokenService::class);

        $token = LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('complete-me'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $completed = $service->complete($token)->fresh();

        $this->assertSame(LeadPortalToken::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNotNull($completed->revoked_at);
    }

    public function test_ensure_for_lead_creates_one_progress_row_only(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalProgressService::class);

        $first = $service->ensureForLead($lead);
        $second = $service->ensureForLead($lead);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('welcome', $first->current_step);
        $this->assertNotNull($first->started_at);
        $this->assertSame(1, LeadPortalProgress::where('lead_id', $lead->id)->count());
    }

    public function test_generate_for_lead_uses_portal_base_url_when_configured(): void
    {
        config()->set('services.portal.base_url', 'https://portal.example.test/');
        config()->set('app.url', 'https://app.example.test');

        $lead = $this->makeLead();
        $service = app(LeadPortalLinkService::class);

        $generated = $service->generateForLead($lead, '127.0.0.1');

        $this->assertArrayHasKey('token', $generated);
        $this->assertArrayHasKey('portal_token', $generated);
        $this->assertArrayHasKey('portal_url', $generated);
        $this->assertSame(
            'https://portal.example.test/portal/'.$generated['token'],
            $generated['portal_url']
        );
        $this->assertSame(
            hash('sha256', $generated['token']),
            $generated['portal_token']->fresh()->token_hash
        );
    }

    public function test_generate_for_lead_falls_back_to_app_url(): void
    {
        config()->set('services.portal.base_url', null);
        config()->set('app.url', 'https://app.example.test/');

        $lead = $this->makeLead();
        $service = app(LeadPortalLinkService::class);

        $generated = $service->generateForLead($lead);

        $this->assertSame(
            'https://app.example.test/portal/'.$generated['token'],
            $generated['portal_url']
        );
    }

    public function test_portal_link_generate_command_prints_pending_link_without_activation(): void
    {
        config()->set('services.portal.base_url', 'https://portal.example.test');

        $lead = $this->makeLead();

        $this->artisan('portal:link-generate', ['leadId' => $lead->id])
            ->expectsOutput('jinx_lead_id: '.$lead->id)
            ->expectsOutput('vicidial_lead_id: '.$lead->vicidial_lead_id)
            ->expectsOutputToContain('Admin/internal diagnostic only')
            ->expectsOutputToContain('token_status: pending')
            ->expectsOutput('activated_at: null')
            ->expectsOutput('expires_at: null')
            ->expectsOutputToContain('portal_url: https://portal.example.test/portal/')
            ->assertExitCode(0);

        $portalToken = LeadPortalToken::query()->where('lead_id', $lead->id)->latest('id')->first();

        $this->assertNotNull($portalToken);
        $this->assertSame(LeadPortalToken::STATUS_PENDING, $portalToken->status);
        $this->assertNull($portalToken->activated_at);
        $this->assertNull($portalToken->expires_at);
    }

    public function test_portal_link_generate_command_accepts_vicidial_lead_id(): void
    {
        config()->set('services.portal.base_url', 'https://portal.example.test');

        $lead = $this->makeLead();

        $this->artisan('portal:link-generate', ['leadId' => $lead->vicidial_lead_id])
            ->expectsOutput('jinx_lead_id: '.$lead->id)
            ->expectsOutput('vicidial_lead_id: '.$lead->vicidial_lead_id)
            ->expectsOutputToContain('token_status: pending')
            ->assertExitCode(0);
    }

    public function test_portal_link_generate_command_can_return_json_and_fail_for_missing_lead(): void
    {
        $this->artisan('portal:link-generate', ['leadId' => 999999, '--json' => true])
            ->expectsOutputToContain('"ok": false')
            ->expectsOutputToContain('"message": "Lead not found for given Jinx ID or Vicidial lead ID: 999999"')
            ->assertExitCode(1);
    }

    public function test_portal_reset_command_dry_run_changes_nothing(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalTokenService::class);
        $issued = $service->issueForLead($lead);

        LeadPortalProgress::create([
            'lead_id' => $lead->id,
            'current_step' => 'review',
            'last_completed_step' => 'credit_check',
            'started_at' => now(),
            'last_seen_at' => now(),
            'completed_at' => now(),
        ]);
        $snapshot = LeadPortalSnapshot::create([
            'lead_id' => $lead->id,
            'snapshot_json' => ['ok' => true],
            'emailed_at' => now(),
        ]);
        LeadPortalEmailClick::create([
            'lead_id' => $lead->id,
            'lead_portal_snapshot_id' => $snapshot->id,
            'click_type' => 'whatsapp',
            'destination_url' => 'https://wa.me/123',
            'clicked_at' => now(),
        ]);

        $lead->update([
            'portal_credit_check_started_at' => now()->subDay(),
            'portal_credit_check_completed_at' => now()->subHours(12),
            'portal_credit_check_last_run_at' => now()->subHours(12),
        ]);

        $this->artisan('portal:reset', ['leadId' => $lead->id])
            ->expectsOutputToContain('DRY RUN ONLY')
            ->expectsOutput('mode: dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('lead_portal_tokens', [
            'id' => $issued['portal_token']->id,
            'status' => LeadPortalToken::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('lead_portal_progress', [
            'lead_id' => $lead->id,
            'current_step' => 'review',
        ]);
        $this->assertDatabaseHas('lead_portal_snapshots', [
            'lead_id' => $lead->id,
        ]);
        $this->assertDatabaseHas('lead_portal_email_clicks', [
            'lead_id' => $lead->id,
        ]);
        $lead->refresh();
        $this->assertNotNull($lead->portal_credit_check_started_at);
        $this->assertNotNull($lead->portal_credit_check_completed_at);
        $this->assertNotNull($lead->portal_credit_check_last_run_at);
    }

    public function test_portal_reset_command_yes_clears_portal_state(): void
    {
        $lead = $this->makeLead();
        $service = app(LeadPortalTokenService::class);
        $issued = $service->issueForLead($lead);

        LeadPortalProgress::create([
            'lead_id' => $lead->id,
            'current_step' => 'review',
            'last_completed_step' => 'credit_check',
            'started_at' => now(),
            'last_seen_at' => now(),
            'completed_at' => now(),
        ]);
        $snapshot = LeadPortalSnapshot::create([
            'lead_id' => $lead->id,
            'snapshot_json' => ['ok' => true],
            'emailed_at' => now(),
        ]);
        LeadPortalEmailClick::create([
            'lead_id' => $lead->id,
            'lead_portal_snapshot_id' => $snapshot->id,
            'click_type' => 'whatsapp',
            'destination_url' => 'https://wa.me/123',
            'clicked_at' => now(),
        ]);

        $lead->update([
            'portal_credit_check_started_at' => now()->subDay(),
            'portal_credit_check_completed_at' => now()->subHours(12),
            'portal_credit_check_last_run_at' => now()->subHours(12),
        ]);

        $this->artisan('portal:reset', ['leadId' => $lead->id, '--yes' => true])
            ->expectsOutput('mode: committed')
            ->expectsOutputToContain('Portal state reset committed.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('lead_portal_tokens', [
            'id' => $issued['portal_token']->id,
            'status' => LeadPortalToken::STATUS_REVOKED,
        ]);

        $this->assertDatabaseHas('lead_portal_progress', [
            'lead_id' => $lead->id,
            'current_step' => 'welcome',
            'last_completed_step' => null,
            'started_at' => null,
            'last_seen_at' => null,
            'completed_at' => null,
        ]);

        $this->assertDatabaseMissing('lead_portal_snapshots', [
            'lead_id' => $lead->id,
        ]);
        $this->assertDatabaseMissing('lead_portal_email_clicks', [
            'lead_id' => $lead->id,
        ]);

        $lead->refresh();
        $this->assertNull($lead->portal_credit_check_started_at);
        $this->assertNull($lead->portal_credit_check_completed_at);
        $this->assertNull($lead->portal_credit_check_last_run_at);
    }

    public function test_portal_reset_command_accepts_vicidial_lead_id(): void
    {
        $lead = $this->makeLead();

        $this->artisan('portal:reset', ['leadId' => $lead->vicidial_lead_id])
            ->expectsOutput('jinx_lead_id: '.$lead->id)
            ->expectsOutput('vicidial_lead_id: '.$lead->vicidial_lead_id)
            ->expectsOutput('mode: dry-run')
            ->assertExitCode(0);
    }

    private function makeLead(): Lead
    {
        return Lead::create([
            'vicidial_lead_id' => 'portal-test-'.uniqid('', true),
        ]);
    }
}
