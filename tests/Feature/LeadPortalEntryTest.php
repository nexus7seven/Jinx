<?php

namespace Tests\Feature;

use App\Mail\LeadPortalSummaryMail;
use App\Models\Lead;
use App\Models\LeadPortalDebt;
use App\Models\LeadPortalEmailClick;
use App\Models\LeadPortalProgress;
use App\Models\LeadPortalSnapshot;
use App\Models\LeadPortalToken;
use App\Services\LeadPortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeadPortalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_token_first_visit_activates_it_and_sets_expiry(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead([
                'dob' => '1985-06-15',
            ]);
            $issued = $service->issueForLead($lead);

            $response = $this->get(route('portal.entry', ['token' => $issued['token']]));

            $response->assertOk()
                ->assertSee('Let&rsquo;s keep your details private', false)
                ->assertSee('Before we continue, please confirm one detail so we know it&rsquo;s you.', false);

            $token = $issued['portal_token']->fresh();
            $this->assertSame(LeadPortalToken::STATUS_ACTIVE, $token->status);
            $this->assertSame('2026-04-29 10:00:00', $token->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-29 10:00:00', $token->expires_at?->format('Y-m-d H:i:s'));
            $this->assertNotNull($token->last_used_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_valid_dob_verifies_and_shows_entry(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead([
                'dob' => '1985-06-15',
                'postcode' => 'SW1A 1AA',
            ]);
            $issued = $service->issueForLead($lead);

            $response = $this->from(route('portal.entry', ['token' => $issued['token']]))
                ->post(route('portal.verify', ['token' => $issued['token']]), [
                    'dob' => '1985-06-15',
                ]);

            $response->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

            $this->get(route('portal.entry', ['token' => $issued['token']]))
                ->assertOk()
                ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
                ->assertSee('We&rsquo;ll guide you through a few simple steps so you can see where things stand.', false)
                ->assertSee('Start');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_valid_normalized_postcode_verifies_and_shows_entry(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'postcode' => 'sw1a 1aa',
        ]);
        $issued = $service->issueForLead($lead);

        $response = $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.verify', ['token' => $issued['token']]), [
                'postcode' => 'SW1A1AA',
            ]);

        $response->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
            ->assertSee('Start');
    }

    public function test_invalid_verification_returns_generic_error(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'postcode' => 'SW1A 1AA',
        ]);
        $issued = $service->issueForLead($lead);

        $response = $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.verify', ['token' => $issued['token']]), [
                'dob' => '2000-01-01',
            ]);

        $response->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'verification' => 'That doesn’t look quite right. Please check and try again.',
            ]);
    }

    public function test_invalid_attempts_become_rate_limited_after_threshold(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from(route('portal.entry', ['token' => $issued['token']]))
                ->post(route('portal.verify', ['token' => $issued['token']]), [
                    'dob' => '2000-01-01',
                ])
                ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
                ->assertSessionHasErrors([
                    'verification' => 'That doesn’t look quite right. Please check and try again.',
                ]);
        }

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.verify', ['token' => $issued['token']]), [
                'dob' => '2000-01-01',
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'verification' => 'Too many attempts. Please wait a little while and try again.',
            ]);
    }

    public function test_once_verified_get_portal_token_goes_straight_to_entry(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead([
                'dob' => '1985-06-15',
            ]);
            $issued = $service->issueForLead($lead);

            $this->post(route('portal.verify', ['token' => $issued['token']]), [
                'dob' => '1985-06-15',
            ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

            Carbon::setTestNow('2026-05-01 12:00:00');

            $response = $this->get(route('portal.entry', ['token' => $issued['token']]));

            $response->assertOk()
                ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
                ->assertSee('Start');

            $token = $issued['portal_token']->fresh();
            $this->assertSame(LeadPortalToken::STATUS_ACTIVE, $token->status);
            $this->assertSame('2026-04-29 10:00:00', $token->activated_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-29 10:00:00', $token->expires_at?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-01 12:00:00', $token->last_used_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_successful_verification_clears_attempt_counter(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->from(route('portal.entry', ['token' => $issued['token']]))
                ->post(route('portal.verify', ['token' => $issued['token']]), [
                    'dob' => '2000-01-01',
                ])
                ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
                ->assertSessionHasErrors([
                    'verification' => 'That doesn’t look quite right. Please check and try again.',
                ]);
        }

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->flushSession();

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.verify', ['token' => $issued['token']]), [
                'dob' => '2000-01-01',
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'verification' => 'That doesn’t look quite right. Please check and try again.',
            ]);
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

    public function test_portal_progress_row_is_created_and_last_seen_is_updated_after_verification(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00');

        try {
            $service = app(LeadPortalTokenService::class);
            $lead = $this->makeLead([
                'dob' => '1985-06-15',
            ]);
            $issued = $service->issueForLead($lead);

            $this->assertSame(0, LeadPortalProgress::where('lead_id', $lead->id)->count());

            $this->post(route('portal.verify', ['token' => $issued['token']]), [
                'dob' => '1985-06-15',
            ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

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

    public function test_verify_route_rejects_invalid_and_non_usable_tokens(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);

        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('revoked-token'),
            'status' => LeadPortalToken::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('completed-token'),
            'status' => LeadPortalToken::STATUS_COMPLETED,
            'completed_at' => now(),
            'revoked_at' => now(),
        ]);

        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        foreach (['not-a-real-token', 'revoked-token', 'completed-token', 'expired-token'] as $rawToken) {
            $this->post(route('portal.verify', ['token' => $rawToken]), [
                'dob' => '1985-06-15',
            ])
                ->assertStatus(410)
                ->assertSee('This link is no longer active')
                ->assertSee('For security, you&rsquo;ll need a new link.', false);
        }
    }

    public function test_invalid_token_shows_expired_page(): void
    {
        $this->get(route('portal.entry', ['token' => 'not-a-real-token']))
            ->assertStatus(410)
            ->assertSee('This link is no longer active')
            ->assertSee('For security, you&rsquo;ll need a new link.', false);
    }

    public function test_lead_with_no_dob_or_postcode_can_verify_gently(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'postcode' => 'anything',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
            ->assertSee('Start');
    }

    public function test_verified_user_sees_welcome_page(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Let&rsquo;s build a clear picture of your situation', false)
            ->assertSee('Start');
    }

    public function test_unverified_user_cannot_post_welcome_completion(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNull($progress);
    }

    public function test_post_welcome_completion_updates_progress_to_details(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('welcome', $progress->last_completed_step);
        $this->assertSame('details', $progress->current_step);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('A few details to get started')
            ->assertSee('Save and continue');
    }

    public function test_invalid_expired_token_cannot_complete_welcome(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('expired-welcome-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.welcome.complete', ['token' => 'expired-welcome-token']))
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_details_page_appears_after_welcome_completion(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('A few details to get started')
            ->assertSee('We use this to match the right information to you.')
            ->assertSee('Save and continue');
    }

    public function test_saving_valid_details_updates_lead_and_advances_progress_to_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.details.save', ['token' => $issued['token']]), [
            'first_name' => 'Alex',
            'last_name' => 'Stone',
            'dob' => '1985-06-15',
            'email' => 'alex@example.test',
            'phone' => '07123456789',
            'postcode' => 'sw1a 1aa',
            'house_number' => '10',
            'address_line_1' => 'Test Street',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame('Alex', $lead->first_name);
        $this->assertSame('Stone', $lead->last_name);
        $this->assertSame('1985-06-15', $lead->dob);
        $this->assertSame('alex@example.test', $lead->email);
        $this->assertSame('07123456789', $lead->phone_number);
        $this->assertSame('SW1A 1AA', $lead->postcode);
        $this->assertSame('10', $lead->house_number);
        $this->assertSame('Test Street', $lead->address_line_1);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('details', $progress->last_completed_step);
        $this->assertSame('debts', $progress->current_step);
    }

    public function test_debts_page_appears_after_details_completion(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeAndDetails($issued['token']);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Let&rsquo;s look at what you owe', false)
            ->assertSee('A rough estimate is absolutely fine.', false)
            ->assertSee('Save and continue');
    }

    public function test_saving_rough_total_updates_lead(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeAndDetails($issued['token']);

        $this->post(route('portal.debts.save', ['token' => $issued['token']]), [
            'estimated_total_debt' => '12345.67',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame('12345.67', (string) $lead->estimated_total_debt);
    }

    public function test_saving_optional_creditor_rows_stores_rows_and_ignores_blanks(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeAndDetails($issued['token']);

        $this->post(route('portal.debts.save', ['token' => $issued['token']]), [
            'estimated_total_debt' => '3000',
            'creditors' => [
                ['creditor_name' => 'Lender One', 'balance' => '1000'],
                ['creditor_name' => '', 'balance' => ''],
                ['creditor_name' => 'Lender Two', 'balance' => '2000'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $rows = LeadPortalDebt::where('lead_id', $lead->id)->where('source', 'portal')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Lender One', $rows[0]->creditor_name);
        $this->assertSame('1000.00', (string) $rows[0]->balance);
        $this->assertSame('Lender Two', $rows[1]->creditor_name);
        $this->assertSame('2000.00', (string) $rows[1]->balance);
    }

    public function test_saving_debts_advances_progress_to_income(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeAndDetails($issued['token']);

        $this->post(route('portal.debts.save', ['token' => $issued['token']]), [
            'estimated_total_debt' => '5000',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('debts', $progress->last_completed_step);
        $this->assertSame('income', $progress->current_step);
    }

    public function test_unverified_users_cannot_save_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.debts.save', ['token' => $issued['token']]), [
            'estimated_total_debt' => '1000',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNull($lead->estimated_total_debt);
    }

    public function test_invalid_or_expired_token_cannot_save_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('debts-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.debts.save', ['token' => 'debts-expired-token']), [
            'estimated_total_debt' => '1000',
        ])
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_income_page_appears_after_debts_completion(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('What&rsquo;s coming in each month?', false)
            ->assertSee('After tax if possible &mdash; a rough estimate is fine.', false)
            ->assertSee('Save and continue');
    }

    public function test_saving_income_updates_lead(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Employed full-time',
            'monthly_income' => '2450.50',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame('Employed full-time', $lead->employment_status);
        $this->assertSame('2450.50', (string) $lead->monthly_income);
    }

    public function test_saving_income_advances_progress_to_costs(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Self-employed',
            'monthly_income' => '1800',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('income', $progress->last_completed_step);
        $this->assertSame('costs', $progress->current_step);
    }

    public function test_unverified_users_cannot_save_income(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Employed full-time',
            'monthly_income' => '2000',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNull($lead->employment_status);
        $this->assertNull($lead->monthly_income);
    }

    public function test_invalid_or_expired_token_cannot_save_income(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('income-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.income.save', ['token' => 'income-expired-token']), [
            'employment_status' => 'Employed full-time',
            'monthly_income' => '2000',
        ])
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_costs_page_appears_after_income_completion(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsAndIncome($issued['token']);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('What are your main monthly costs?', false)
            ->assertSee('Estimates are fine &mdash; this just helps build a clearer picture.', false)
            ->assertSee('Save and continue');
    }

    public function test_saving_costs_updates_lead(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsAndIncome($issued['token']);

        $this->post(route('portal.costs.save', ['token' => $issued['token']]), [
            'monthly_housing_cost' => '950.00',
            'monthly_council_tax' => '120.50',
            'monthly_utilities_cost' => '180.00',
            'monthly_food_travel_cost' => '340.25',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame('950.00', (string) $lead->monthly_housing_cost);
        $this->assertSame('120.50', (string) $lead->monthly_council_tax);
        $this->assertSame('180.00', (string) $lead->monthly_utilities_cost);
        $this->assertSame('340.25', (string) $lead->monthly_food_travel_cost);
    }

    public function test_saving_costs_advances_progress_to_credit_check(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsAndIncome($issued['token']);

        $this->post(route('portal.costs.save', ['token' => $issued['token']]), [
            'monthly_housing_cost' => '900',
            'monthly_council_tax' => '100',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('costs', $progress->last_completed_step);
        $this->assertSame('credit_check', $progress->current_step);
    }

    public function test_unverified_users_cannot_save_costs(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.costs.save', ['token' => $issued['token']]), [
            'monthly_housing_cost' => '900',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNull($lead->monthly_housing_cost);
    }

    public function test_invalid_or_expired_token_cannot_save_costs(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('costs-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.costs.save', ['token' => 'costs-expired-token']), [
            'monthly_housing_cost' => '900',
        ])
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_credit_check_intro_appears_after_costs_completion(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Let&rsquo;s fill in the gaps', false)
            ->assertSee('We can securely check your credit file to help find anything you may have missed.', false)
            ->assertSee('This won&rsquo;t affect your credit score.', false)
            ->assertSee('Continue');
    }

    public function test_credit_check_start_placeholder_sets_started_and_last_run_timestamps(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->assertNull($lead->portal_credit_check_started_at);
        $this->assertNull($lead->portal_credit_check_last_run_at);

        $this->post(route('portal.credit-check.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNotNull($lead->portal_credit_check_started_at);
        $this->assertNotNull($lead->portal_credit_check_last_run_at);
    }

    public function test_credit_check_start_advances_progress_to_review(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->post(route('portal.credit-check.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check', $progress->last_completed_step);
        $this->assertSame('review', $progress->current_step);
    }

    public function test_second_credit_check_start_does_not_reset_timestamps(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->post(route('portal.credit-check.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $firstStartedAt = $lead->portal_credit_check_started_at?->copy();
        $firstLastRunAt = $lead->portal_credit_check_last_run_at?->copy();

        $this->post(route('portal.credit-check.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertTrue($lead->portal_credit_check_started_at?->equalTo($firstStartedAt));
        $this->assertTrue($lead->portal_credit_check_last_run_at?->equalTo($firstLastRunAt));
    }

    public function test_unverified_users_cannot_start_credit_check(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.credit-check.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNull($lead->portal_credit_check_started_at);
        $this->assertNull($lead->portal_credit_check_last_run_at);
    }

    public function test_invalid_or_expired_token_cannot_start_credit_check(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('credit-check-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.credit-check.start', ['token' => 'credit-check-expired-token']))
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_review_page_appears_after_credit_check_start(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToReview($issued['token']);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Review what we have so far')
            ->assertSee('Your details')
            ->assertSee('What you owe')
            ->assertSee('Monthly picture');
    }

    public function test_review_page_shows_masked_personal_details(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'first_name' => 'Alice',
            'last_name' => 'Baker',
            'dob' => '1985-06-15',
            'postcode' => 'SW1A 1AA',
            'house_number' => '22',
            'address_line_1' => 'Example Street',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToReview($issued['token']);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('A**** B****', false)
            ->assertSee('**/**/1985', false)
            ->assertSee('SW1****', false)
            ->assertSee('22 ********', false);
    }

    public function test_review_page_shows_debt_and_monthly_picture_values(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->post(route('portal.credit-check.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('£5,000.00', false)
            ->assertSee('£2,000.00', false)
            ->assertSee('£900.00', false)
            ->assertSee('£100.00', false)
            ->assertSee('£150.00', false)
            ->assertSee('£300.00', false);
    }

    public function test_finishing_review_advances_progress_to_complete_pending(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToReview($issued['token']);

        $this->post(route('portal.review.finish', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('review', $progress->last_completed_step);
        $this->assertSame('complete_pending', $progress->current_step);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('You&rsquo;re all set', false)
            ->assertSee('Finish');
    }

    public function test_unverified_cannot_finish_review(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.review.finish', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNull($progress);
    }

    public function test_invalid_or_expired_token_cannot_finish_review(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('review-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.review.finish', ['token' => 'review-expired-token']))
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_completing_from_complete_pending_creates_snapshot(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);

        $this->post(route('portal.complete', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('All done');

        $this->assertDatabaseHas('lead_portal_snapshots', [
            'lead_id' => $lead->id,
        ]);
    }

    public function test_completion_sends_summary_email_when_lead_has_email_and_sets_emailed_at(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('We&rsquo;ve sent your summary by email.', false);

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail) use ($lead) {
            return $mail->hasTo($lead->email);
        });

        $snapshot = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->first();
        $this->assertNotNull($snapshot?->emailed_at);
    }

    public function test_completion_does_not_fail_when_lead_email_is_missing(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => null,
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('We couldn&rsquo;t send an email because no email address was provided.', false);

        Mail::assertNothingSent();

        $snapshot = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->first();
        $this->assertNull($snapshot?->emailed_at);
    }

    public function test_snapshot_contains_expected_sections(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'first_name' => 'Alice',
            'last_name' => 'Baker',
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
            'house_number' => '10',
            'address_line_1' => 'Downing Street',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        LeadPortalDebt::create([
            'lead_id' => $lead->id,
            'creditor_name' => 'Example Lender',
            'balance' => '1234.56',
            'source' => 'portal',
        ]);
        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $snapshot = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->first();
        $this->assertNotNull($snapshot);
        $json = $snapshot->snapshot_json;
        $this->assertIsArray($json);
        $this->assertArrayHasKey('details', $json);
        $this->assertArrayHasKey('debts', $json);
        $this->assertArrayHasKey('income', $json);
        $this->assertArrayHasKey('costs', $json);
        $this->assertArrayHasKey('credit_check', $json);
        $this->assertSame('Alice', $json['details']['first_name'] ?? null);
        $this->assertSame('Baker', $json['details']['last_name'] ?? null);
        $this->assertSame('5000.00', (string) ($json['debts']['estimated_total_debt'] ?? ''));
        $this->assertSame('Employed full-time', $json['income']['employment_status'] ?? null);
        $this->assertSame('2000.00', (string) ($json['income']['monthly_income'] ?? ''));
        $this->assertSame('900.00', (string) ($json['costs']['monthly_housing_cost'] ?? ''));
        $this->assertSame('100.00', (string) ($json['costs']['monthly_council_tax'] ?? ''));
        $this->assertSame('150.00', (string) ($json['costs']['monthly_utilities_cost'] ?? ''));
        $this->assertSame('300.00', (string) ($json['costs']['monthly_food_travel_cost'] ?? ''));
        $this->assertNotNull($json['credit_check']['portal_credit_check_started_at'] ?? null);
        $this->assertNotNull($json['credit_check']['portal_credit_check_last_run_at'] ?? null);
        $this->assertSame('Example Lender', $json['debts']['portal_debts'][0]['creditor_name'] ?? null);
    }

    public function test_summary_email_content_includes_financial_sections_and_omits_full_dob_and_address(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'first_name' => 'Alice',
            'last_name' => 'Baker',
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
            'postcode' => 'SW1A 1AA',
            'house_number' => '10',
            'address_line_1' => 'Downing Street',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        LeadPortalDebt::create([
            'lead_id' => $lead->id,
            'creditor_name' => 'Example Lender',
            'balance' => '1234.56',
            'source' => 'portal',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Here&rsquo;s the summary we put together from the details you provided.')
                && str_contains($html, 'Estimated total debt:')
                && str_contains($html, 'Monthly picture')
                && str_contains($html, '£5,000.00')
                && str_contains($html, '£2,000.00')
                && ! str_contains($html, '1985-06-15')
                && ! str_contains($html, 'Downing Street');
        });
    }

    public function test_summary_email_whatsapp_cta_appears_only_when_configured(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
        ]);
        $issued = $service->issueForLead($lead);

        config(['services.portal.whatsapp_url' => null]);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            return ! str_contains($mail->render(), 'Message us on WhatsApp');
        });

        Mail::fake();
        $leadWithCta = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => 'portal-cta@example.com',
        ]);
        $issuedWithCta = $service->issueForLead($leadWithCta);
        config(['services.portal.whatsapp_url' => 'https://wa.me/441234567890']);

        $this->verifyThenCompleteToCompletePending($issuedWithCta['token']);
        $this->post(route('portal.complete', ['token' => $issuedWithCta['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Message us on WhatsApp')
                && str_contains($html, 'https://wa.me/441234567890');
        });
    }

    public function test_whatsapp_cta_uses_tracking_route_not_direct_whatsapp_url(): void
    {
        Mail::fake();

        config(['services.portal.whatsapp_url' => 'https://wa.me/441234567890']);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $snapshot = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->firstOrFail();
        $expectedTrackingUrl = route('portal.summary.click', ['snapshot' => $snapshot->id, 'type' => 'whatsapp']);

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail) use ($expectedTrackingUrl): bool {
            $html = $mail->render();

            return str_contains($html, $expectedTrackingUrl)
                && ! str_contains($html, 'href="https://wa.me/');
        });
    }

    public function test_click_tracking_route_creates_row_and_redirects_to_whatsapp(): void
    {
        config(['services.portal.whatsapp_url' => 'https://wa.me/441234567890']);

        $lead = $this->makeLead();
        $snapshot = LeadPortalSnapshot::create([
            'lead_id' => $lead->id,
            'snapshot_json' => ['lead_id' => $lead->id],
        ]);

        $response = $this->withHeader('referer', 'https://example.test/mail')
            ->withHeader('user-agent', 'PortalTestAgent/1.0')
            ->get(route('portal.summary.click', ['snapshot' => $snapshot->id, 'type' => 'whatsapp']));

        $response->assertRedirect('https://wa.me/441234567890');

        $this->assertDatabaseHas('lead_portal_email_clicks', [
            'lead_id' => $lead->id,
            'lead_portal_snapshot_id' => $snapshot->id,
            'click_type' => 'whatsapp',
            'destination_url' => 'https://wa.me/441234567890',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PortalTestAgent/1.0',
        ]);

        $click = LeadPortalEmailClick::query()->latest('id')->first();
        $this->assertNotNull($click?->clicked_at);
        $this->assertSame('https://example.test/mail', $click?->raw_context_json['referer'] ?? null);
    }

    public function test_unsupported_click_type_is_rejected(): void
    {
        config(['services.portal.whatsapp_url' => 'https://wa.me/441234567890']);

        $snapshot = LeadPortalSnapshot::create([
            'lead_id' => null,
            'snapshot_json' => [],
        ]);

        $this->get(route('portal.summary.click', ['snapshot' => $snapshot->id, 'type' => 'email']))
            ->assertNotFound();
    }

    public function test_missing_whatsapp_destination_is_rejected(): void
    {
        config(['services.portal.whatsapp_url' => null]);

        $snapshot = LeadPortalSnapshot::create([
            'lead_id' => null,
            'snapshot_json' => [],
        ]);

        $this->get(route('portal.summary.click', ['snapshot' => $snapshot->id, 'type' => 'whatsapp']))
            ->assertNotFound();
    }

    public function test_completing_marks_progress_complete_and_token_completed(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('complete', $progress->current_step);
        $this->assertNotNull($progress->completed_at);

        $token = $issued['portal_token']->fresh();
        $this->assertSame(LeadPortalToken::STATUS_COMPLETED, $token->status);
        $this->assertNotNull($token->completed_at);
        $this->assertNotNull($token->revoked_at);
    }

    public function test_after_completion_get_portal_token_shows_expired_page(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_unverified_cannot_complete_portal(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertDatabaseMissing('lead_portal_snapshots', [
            'lead_id' => $lead->id,
        ]);
    }

    public function test_invalid_or_expired_token_cannot_complete_portal(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('complete-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.complete', ['token' => 'complete-expired-token']))
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    public function test_unverified_users_cannot_save_details(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.details.save', ['token' => $issued['token']]), [
            'first_name' => 'Alex',
            'last_name' => 'Stone',
            'postcode' => 'SW1A 1AA',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNotSame('Alex', $lead->first_name);
    }

    public function test_invalid_or_expired_token_cannot_save_details(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('details-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->post(route('portal.details.save', ['token' => 'details-expired-token']), [
            'first_name' => 'Alex',
            'last_name' => 'Stone',
            'postcode' => 'SW1A 1AA',
        ])
            ->assertStatus(410)
            ->assertSee('This link is no longer active');
    }

    private function makeLead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'vicidial_lead_id' => 'portal-entry-test-'.uniqid('', true),
        ], $overrides));
    }

    private function verifyThenCompleteWelcomeAndDetails(string $rawToken): void
    {
        $this->post(route('portal.verify', ['token' => $rawToken]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));

        $this->post(route('portal.welcome.complete', ['token' => $rawToken]))
            ->assertRedirect(route('portal.entry', ['token' => $rawToken]));

        $this->post(route('portal.details.save', ['token' => $rawToken]), [
            'first_name' => 'Alex',
            'last_name' => 'Stone',
            'dob' => '1985-06-15',
            'postcode' => 'SW1A 1AA',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }

    private function verifyThenCompleteWelcomeDetailsAndDebts(string $rawToken): void
    {
        $this->verifyThenCompleteWelcomeAndDetails($rawToken);

        $this->post(route('portal.debts.save', ['token' => $rawToken]), [
            'estimated_total_debt' => '5000',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }

    private function verifyThenCompleteWelcomeDetailsDebtsAndIncome(string $rawToken): void
    {
        $this->verifyThenCompleteWelcomeDetailsAndDebts($rawToken);

        $this->post(route('portal.income.save', ['token' => $rawToken]), [
            'employment_status' => 'Employed full-time',
            'monthly_income' => '2000',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }

    private function verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts(string $rawToken): void
    {
        $this->verifyThenCompleteWelcomeDetailsDebtsAndIncome($rawToken);

        $this->post(route('portal.costs.save', ['token' => $rawToken]), [
            'monthly_housing_cost' => '900',
            'monthly_council_tax' => '100',
            'monthly_utilities_cost' => '150',
            'monthly_food_travel_cost' => '300',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }

    private function verifyThenCompleteToReview(string $rawToken): void
    {
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($rawToken);

        $this->post(route('portal.credit-check.start', ['token' => $rawToken]))
            ->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }

    private function verifyThenCompleteToCompletePending(string $rawToken): void
    {
        $this->verifyThenCompleteToReview($rawToken);

        $this->post(route('portal.review.finish', ['token' => $rawToken]))
            ->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }
}
