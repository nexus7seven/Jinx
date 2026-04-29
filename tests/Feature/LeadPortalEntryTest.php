<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadPortalProgress;
use App\Models\LeadPortalToken;
use App\Services\LeadPortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeadPortalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_token_first_visit_activates_it_and_sets_expiry(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead();
            $issued = $service->issueForLead($lead);

            $response = $this->get(route('portal.entry', ['token' => $issued['token']]));

            $response->assertOk()
                ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
                ->assertSee('This secure link lets you continue your details.', false)
                ->assertSee('welcome');

            $token = $issued['portal_token']->fresh();
            $this->assertSame(LeadPortalToken::STATUS_ACTIVE, $token->status);
            $this->assertSame('2026-04-29 10:00:00', $token->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-29 10:00:00', $token->expires_at?->format('Y-m-d H:i:s'));
            $this->assertNotNull($token->last_used_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_active_token_can_revisit(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead();
            $issued = $service->issueForLead($lead);

            $this->get(route('portal.entry', ['token' => $issued['token']]))->assertOk();

            Carbon::setTestNow('2026-05-01 12:00:00');

            $response = $this->get(route('portal.entry', ['token' => $issued['token']]));

            $response->assertOk()
                ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
                ->assertSee('welcome');

            $token = $issued['portal_token']->fresh();
            $this->assertSame(LeadPortalToken::STATUS_ACTIVE, $token->status);
            $this->assertSame('2026-04-29 10:00:00', $token->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-29 10:00:00', $token->expires_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-01 12:00:00', $token->last_used_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revoked_completed_and_expired_tokens_show_expired_page(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();

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

        foreach (['revoked-token', 'completed-token', 'expired-token'] as $rawToken) {
            $this->get(route('portal.entry', ['token' => $rawToken]))
                ->assertStatus(410)
                ->assertSee('This link is no longer active')
                ->assertSee('For security, you&rsquo;ll need a new link.', false);
        }

        $this->assertSame(LeadPortalToken::STATUS_REVOKED, $revoked->fresh()->status);
        $this->assertSame(LeadPortalToken::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(LeadPortalToken::STATUS_EXPIRED, $expired->fresh()->status);
    }

    public function test_portal_progress_row_is_created_and_last_seen_is_updated(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead();
            $issued = $service->issueForLead($lead);

            $this->assertSame(0, LeadPortalProgress::where('lead_id', $lead->id)->count());

            $this->get(route('portal.entry', ['token' => $issued['token']]))->assertOk();

            $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();

            $this->assertNotNull($progress);
            $this->assertSame('welcome', $progress->current_step);
            $this->assertNotNull($progress->started_at);
            $this->assertSame('2026-04-29 10:00:00', $progress->last_seen_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_token_shows_expired_page(): void
    {
        $this->get(route('portal.entry', ['token' => 'not-a-real-token']))
            ->assertStatus(410)
            ->assertSee('This link is no longer active')
            ->assertSee('For security, you&rsquo;ll need a new link.', false);
    }

    private function makeLead(): Lead
    {
        return Lead::create([
            'vicidial_lead_id' => 'portal-entry-test-'.uniqid('', true),
        ]);
    }
}
