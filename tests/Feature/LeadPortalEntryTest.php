<?php

namespace Tests\Feature;

use App\Mail\LeadPortalSummaryMail;
use App\Models\Creditor;
use App\Models\CreditCheckJobLog;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\LeadPortalDebt;
use App\Models\LeadPortalEmailClick;
use App\Models\LeadPortalProgress;
use App\Models\LeadPortalSnapshot;
use App\Models\LeadPortalToken;
use App\Models\User;
use App\Services\LeadPortalDebtPresenter;
use App\Services\LeadPortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    public function test_verify_page_only_has_postcode(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('name="postcode"', false)
            ->assertDontSee('name="dob"', false);
    }

    public function test_matching_postcode_verifies(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['postcode' => 'sw1a 1aa']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'postcode' => 'SW1A1AA',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));
    }

    public function test_missing_postcode_is_saved_and_verified(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['postcode' => null]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'postcode' => 'sw1a 1aa',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertSame('SW1A 1AA', $lead->fresh()->postcode);
    }

    public function test_wrong_postcode_fails_generically(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['postcode' => 'SW1A 1AA']);
        $issued = $service->issueForLead($lead);

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.verify', ['token' => $issued['token']]), [
                'postcode' => 'AB1 2CD',
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'verification' => 'That doesn’t look quite right. Please check and try again.',
            ]);
    }

    public function test_dob_not_used_for_verification(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'postcode' => 'SW1A 1AA',
        ]);
        $issued = $service->issueForLead($lead);

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.verify', ['token' => $issued['token']]), [
                'dob' => '1985-06-15',
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'verification' => 'That doesn’t look quite right. Please check and try again.',
            ]);
    }

    public function test_customer_facing_creditor_name_uses_reference(): void
    {
        $presenter = app(LeadPortalDebtPresenter::class);
        $debt = new Debt([
            'reference' => 'Raw creditor: Example Finance Ltd',
        ]);
        $debt->setRelation('creditor', new Creditor(['name' => 'Could Not Match']));

        $this->assertSame('Example Finance Ltd', $presenter->customerFacingCreditorName($debt));
    }

    public function test_customer_facing_creditor_name_returns_normal_name(): void
    {
        $presenter = app(LeadPortalDebtPresenter::class);
        $debt = new Debt(['reference' => null]);
        $debt->setRelation('creditor', new Creditor(['name' => 'Barclays']));

        $this->assertSame('Barclays', $presenter->customerFacingCreditorName($debt));
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
            ->assertDontSee('Address line 1')
            ->assertSee('Save and continue');
    }

    public function test_saving_valid_details_updates_lead_and_advances_progress_to_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'address_line_1' => 'Existing Street',
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.details.save', ['token' => $issued['token']]), [
            'title' => 'Mr',
            'first_name' => 'Alex',
            'last_name' => 'Stone',
            'dob' => '1985-06-15',
            'email' => 'alex@example.test',
            'phone' => '07123456789',
            'postcode' => 'sw1a 1aa',
            'house_number' => '10',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame('Mr', $lead->title);
        $this->assertSame('Alex', $lead->first_name);
        $this->assertSame('Stone', $lead->last_name);
        $this->assertSame('1985-06-15', $lead->dob);
        $this->assertSame('alex@example.test', $lead->email);
        $this->assertSame('07123456789', $lead->phone_number);
        $this->assertSame('SW1A 1AA', $lead->postcode);
        $this->assertSame('10', $lead->house_number);
        $this->assertSame('Existing Street', $lead->address_line_1);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('details', $progress->last_completed_step);
        $this->assertSame('debts', $progress->current_step);
    }

    public function test_details_page_includes_title_dropdown_options(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('name="title"', false)
            ->assertSee('Mr')
            ->assertSee('Mrs')
            ->assertSee('Miss')
            ->assertSee('Ms')
            ->assertSee('Dr')
            ->assertSee('Other');
    }

    public function test_saving_details_requires_required_identity_fields_when_missing_on_lead(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'title' => null,
            'first_name' => null,
            'last_name' => null,
            'phone_number' => null,
            'postcode' => null,
            'house_number' => null,
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.welcome.complete', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.details.save', ['token' => $issued['token']]), [])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors(['title', 'first_name', 'last_name', 'phone', 'postcode', 'house_number']);
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
            ->assertSee('We&rsquo;ll use the credit check to help fill in anything you may have missed.', false)
            ->assertDontSee('Optional lenders')
            ->assertDontSee('Creditor name')
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

    public function test_saving_debts_does_not_wipe_existing_portal_debts_when_rows_not_submitted(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeAndDetails($issued['token']);

        LeadPortalDebt::create([
            'lead_id' => $lead->id,
            'creditor_name' => 'Existing Lender',
            'balance' => '1000.00',
            'source' => 'portal',
        ]);

        $this->post(route('portal.debts.save', ['token' => $issued['token']]), [
            'estimated_total_debt' => '3000',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $rows = LeadPortalDebt::where('lead_id', $lead->id)->where('source', 'portal')->orderBy('id')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Existing Lender', $rows[0]->creditor_name);
        $this->assertSame('1000.00', (string) $rows[0]->balance);
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
            ->assertSee('Monthly income after tax', false)
            ->assertSee('Estimate is fine.', false)
            ->assertSee('Employed full-time')
            ->assertSee('Self-employed')
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

    public function test_employed_income_maps_to_financial_statement_salary(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Employed full-time',
            'monthly_income' => '2450.50',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame(2450.50, $lead->financial_statement['income']['salary']);
    }

    public function test_self_employed_income_maps_to_self_employed_key(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Self-employed',
            'monthly_income' => '1800',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame(1800.0, $lead->financial_statement['income']['self_employed']);
    }

    public function test_benefits_income_maps_to_universal_credit_key(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Benefits',
            'monthly_income' => '900',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame(900.0, $lead->financial_statement['income']['universal_credit']);
    }

    public function test_pension_income_maps_to_pensions_key(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Pension',
            'monthly_income' => '1200',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame(1200.0, $lead->financial_statement['income']['pensions']);
    }

    public function test_saving_income_rejects_invalid_employment_status_option(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.income.save', ['token' => $issued['token']]), [
                'employment_status' => 'Invalid option',
                'monthly_income' => '2000',
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors('employment_status');
    }

    public function test_monthly_income_accepts_currency_string_format_and_saves_decimal_value(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsAndDebts($issued['token']);

        $this->post(route('portal.income.save', ['token' => $issued['token']]), [
            'employment_status' => 'Employed full-time',
            'monthly_income' => '£1,500.50',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame('1500.50', (string) $lead->monthly_income);
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

    public function test_portal_costs_map_to_financial_statement_expenditure_keys(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsAndIncome($issued['token']);

        $this->post(route('portal.costs.save', ['token' => $issued['token']]), [
            'monthly_housing_cost' => '900',
            'monthly_council_tax' => '100',
            'monthly_utilities_cost' => '150',
            'monthly_food_travel_cost' => '300',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $expenditure = $lead->financial_statement['expenditure'];
        $this->assertSame(900.0, $expenditure['rent_mortgage']);
        $this->assertSame(100.0, $expenditure['council_tax']);
        $this->assertSame(50.0, $expenditure['electric']);
        $this->assertSame(50.0, $expenditure['gas']);
        $this->assertSame(50.0, $expenditure['water']);
        $this->assertSame(300.0, $expenditure['food']);
        $this->assertArrayHasKey('public_transport', $expenditure);
        $this->assertArrayHasKey('fuel', $expenditure);
    }

    public function test_portal_financial_statement_mapping_preserves_unrelated_existing_values(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'financial_statement' => [
                'schema_version' => 1,
                'guidelines_version' => config('sfs_spending_guidelines.version'),
                'household' => [
                    'adults' => 2,
                    'children_under_16' => 1,
                    'children_16_18' => 0,
                ],
                'income' => [
                    'partner_salary' => 777,
                ],
                'expenditure' => [
                    'internet_tv' => 44,
                ],
            ],
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsAndIncome($issued['token']);

        $this->post(route('portal.costs.save', ['token' => $issued['token']]), [
            'monthly_housing_cost' => '900',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertSame(2, $lead->financial_statement['household']['adults']);
        $this->assertSame(1, $lead->financial_statement['household']['children_under_16']);
        $this->assertSame(777.0, $lead->financial_statement['income']['partner_salary']);
        $this->assertSame(44.0, $lead->financial_statement['expenditure']['internet_tv']);
        $this->assertSame(900.0, $lead->financial_statement['expenditure']['rent_mortgage']);
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
            ->assertSee('Start check')
            ->assertSee(route('portal.credit-check.v3.start', ['token' => $issued['token']]), false)
            ->assertDontSee(route('portal.credit-check.start', ['token' => $issued['token']]), false);
    }

    public function test_credit_check_start_form_submission_uses_v3_flow_and_redirects(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-form-start',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->assertNull($lead->portal_credit_check_started_at);
        $this->assertNull($lead->portal_credit_check_last_run_at);

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNotNull($lead->portal_credit_check_started_at);
        $this->assertNotNull($lead->portal_credit_check_last_run_at);
        $this->assertDatabaseHas('credit_check_job_logs', [
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-form-start',
        ]);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_running', $progress->current_step);
    }

    public function test_portal_v3_credit_check_start_requires_verified_session(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-1',
                'queued' => true,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->postJson(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'message' => 'Verification required.',
            ]);
    }

    public function test_credit_check_start_is_blocked_and_returns_to_details_when_required_fields_missing(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'first_name' => null,
            'last_name' => null,
            'title' => null,
            'phone_number' => null,
            'postcode' => null,
            'house_number' => null,
        ]);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('details', $progress->current_step);
        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertSee('We need a few details before we can start the check.');
        Http::assertNothingSent();
    }

    public function test_portal_v3_credit_check_start_creates_job_log_and_sets_running_step(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-2',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->postJson(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'running' => true,
            ]);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_running', $progress->current_step);
        $this->assertDatabaseHas('credit_check_job_logs', [
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-2',
        ]);
    }

    public function test_portal_start_with_listener_failure_moves_to_credit_check_failed(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => false,
                'message' => 'Listener down',
            ], 500),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_failed', $progress->current_step);
    }

    public function test_portal_start_with_no_external_job_id_moves_to_credit_check_failed(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_failed', $progress->current_step);
    }

    public function test_credit_check_failed_page_try_again_posts_to_v3_start_route(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_failed', 'last_completed_step' => 'credit_check']
        );

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Something went wrong while checking your information. You can try again now. You can check your details and try again.')
            ->assertSee('Try credit check again')
            ->assertSee('Back to details')
            ->assertDontSee('Your next step is coming soon')
            ->assertSee(route('portal.credit-check.v3.start', ['token' => $issued['token']]), false)
            ->assertDontSee(route('portal.credit-check.start', ['token' => $issued['token']]), false);
    }

    public function test_retry_after_failed_job_creates_new_credit_check_job_log(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-retry-failed',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-old-failed',
            'status' => CreditCheckJobLog::STATUS_FAILED,
            'friendly_status' => 'Failed',
            'started_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(4),
        ]);

        $beforeCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $afterCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();
        $this->assertSame($beforeCount + 1, $afterCount);
        $this->assertDatabaseHas('credit_check_job_logs', [
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-retry-failed',
        ]);
    }

    public function test_retry_after_timeout_job_creates_new_credit_check_job_log(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-retry-timeout',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-old-timeout',
            'status' => CreditCheckJobLog::STATUS_TIMEOUT,
            'friendly_status' => 'Timed out',
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(19),
        ]);

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertDatabaseHas('credit_check_job_logs', [
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-retry-timeout',
        ]);
    }

    public function test_retry_from_failed_step_resets_portal_credit_check_state_and_starts_fresh_job(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-retry-reset',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $portalToken = $service->resolveRawToken($issued['token']);
        $this->assertNotNull($portalToken);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $lead->portal_credit_check_started_at = now()->subMinutes(10);
        $lead->portal_credit_check_completed_at = now()->subMinutes(9);
        $lead->portal_credit_check_last_run_at = now()->subMinutes(8);
        $lead->save();

        $snapshot = LeadPortalSnapshot::create([
            'lead_id' => $lead->id,
            'snapshot_json' => ['ok' => true],
            'emailed_at' => null,
        ]);
        LeadPortalEmailClick::create([
            'lead_id' => $lead->id,
            'lead_portal_snapshot_id' => $snapshot->id,
            'click_type' => 'whatsapp',
            'destination_url' => 'https://wa.me/123456',
            'clicked_at' => now(),
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_name' => 'Keep Debt',
            'balance' => 111.11,
            'source_expected' => 'credit_check',
            'last_updated' => now(),
            'is_valid_debt' => 1,
        ]);
        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-old-failed-reset',
            'status' => CreditCheckJobLog::STATUS_FAILED,
            'friendly_status' => 'Failed',
            'started_at' => now()->subMinutes(6),
            'ended_at' => now()->subMinutes(5),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_failed', 'last_completed_step' => 'credit_check', 'completed_at' => now()->subMinute()]
        );

        $sessionKey = 'portal_credit_check_questions_'.$lead->id.'_'.$portalToken->id;
        $beforeTokenCount = LeadPortalToken::where('lead_id', $lead->id)->count();
        $beforeSnapshotCount = LeadPortalSnapshot::where('lead_id', $lead->id)->count();
        $beforeClickCount = LeadPortalEmailClick::where('lead_id', $lead->id)->count();
        $beforeDebtCount = Debt::where('lead_id', $lead->id)->count();
        $beforeLogCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();

        $this->withSession([$sessionKey => [['id' => 'q1', 'question' => 'cached?']]])
            ->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $this->assertNotNull($lead->portal_credit_check_started_at);
        $this->assertNull($lead->portal_credit_check_completed_at);
        $this->assertNotNull($lead->portal_credit_check_last_run_at);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check', $progress->last_completed_step);
        $this->assertSame('credit_check_running', $progress->current_step);
        $this->assertNull($progress->completed_at);

        $this->assertSame($beforeTokenCount, LeadPortalToken::where('lead_id', $lead->id)->count());
        $this->assertSame($beforeSnapshotCount, LeadPortalSnapshot::where('lead_id', $lead->id)->count());
        $this->assertSame($beforeClickCount, LeadPortalEmailClick::where('lead_id', $lead->id)->count());
        $this->assertSame($beforeDebtCount, Debt::where('lead_id', $lead->id)->count());
        $this->assertSame($beforeLogCount + 1, CreditCheckJobLog::where('lead_id', $lead->id)->count());

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSessionMissing($sessionKey);
    }

    public function test_retry_while_job_running_does_not_create_duplicate_job_log(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-running',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now()->subMinutes(2),
        ]);

        $beforeCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();
        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));
        $afterCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();

        $this->assertSame($beforeCount, $afterCount);
        Http::assertNothingSent();
    }

    public function test_stale_active_job_is_marked_timeout_and_retry_starts_fresh_job(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        config()->set('services.credit_check_v3_listener.portal_running_timeout_seconds', 60);
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-retry-stale-active',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $oldLog = CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-stale-active',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now()->subMinutes(5),
        ]);

        $beforeCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();
        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $afterCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();
        $this->assertSame($beforeCount + 1, $afterCount);
        $this->assertDatabaseHas('credit_check_job_logs', [
            'id' => $oldLog->id,
            'status' => CreditCheckJobLog::STATUS_TIMEOUT,
        ]);
        $this->assertDatabaseHas('credit_check_job_logs', [
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-retry-stale-active',
        ]);
    }

    public function test_retry_after_completed_check_does_not_create_new_job_and_moves_to_credit_report_debts(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-complete',
            'status' => CreditCheckJobLog::STATUS_SUCCESS,
            'friendly_status' => 'Completed',
            'started_at' => now()->subMinutes(10),
            'ended_at' => now()->subMinutes(8),
        ]);

        $lead->portal_credit_check_completed_at = now()->subMinutes(8);
        $lead->save();

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_failed']
        );

        $beforeCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();
        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));
        $afterCount = CreditCheckJobLog::where('lead_id', $lead->id)->count();

        $this->assertSame($beforeCount, $afterCount);
        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_report_debts', $progress->current_step);
        Http::assertNothingSent();
    }

    public function test_portal_credit_check_last_run_at_alone_does_not_block_retry_after_failed_job(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-retry-last-run',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $lead->portal_credit_check_last_run_at = now()->subMinutes(1);
        $lead->save();

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-last-run-failed',
            'status' => CreditCheckJobLog::STATUS_FAILED,
            'friendly_status' => 'Failed',
            'started_at' => now()->subMinutes(4),
            'ended_at' => now()->subMinutes(3),
        ]);

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertDatabaseHas('credit_check_job_logs', [
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-retry-last-run',
        ]);
    }

    public function test_invalid_portal_token_cannot_start_or_poll_credit_check_v3(): void
    {
        $this->postJson(route('portal.credit-check.v3.start', ['token' => 'invalid-token']))
            ->assertStatus(410);

        $this->getJson(route('portal.credit-check.poll', ['token' => 'invalid-token']))
            ->assertStatus(410);
    }

    public function test_unverified_portal_session_cannot_poll_credit_check_v3(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token']]))
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'message' => 'Verification required.',
            ]);
    }

    public function test_portal_v3_poll_returns_safe_json_and_does_not_accept_tampered_ids(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-3',
                'queued' => false,
            ], 200),
            'http://listener.test/health' => Http::response([
                'ok' => true,
                'activeJob' => true,
            ], 200),
            'http://listener.test/jobs/portal-job-3/state' => Http::response([
                'ok' => true,
                'active' => true,
                'activeJob' => ['leadId' => 1],
                'latestStatus' => ['data' => ['step' => 'security_questions']],
            ], 200),
            'http://listener.test/jobs/portal-job-3/questions' => Http::response([
                'payload' => [
                    'questions' => [
                        [
                            'id' => 'q1',
                            'question' => 'Sample question?',
                            'answers' => [
                                ['label' => 'Yes', 'value' => 'yes'],
                                ['label' => 'No', 'value' => 'no'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://listener.test/jobs/portal-job-3/report-data' => Http::response(['payload' => []], 200),
            'http://listener.test/jobs/portal-job-3/pdf-state' => Http::response(['payload' => ['status' => 'pending']], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->postJson(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertOk();

        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token'], 'lead_id' => 999, 'job_id' => 'other']))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'running' => true,
                'questions_required' => true,
            ])
            ->assertJsonMissingPath('external_job_id')
            ->assertJsonMissingPath('credit_check_job_log_id');

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_questions', $progress->current_step);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('We need to confirm a few details')
            ->assertSee('These questions help match your credit file securely.')
            ->assertSee('type="radio"', false)
            ->assertDontSee('type="text"', false);
    }

    public function test_portal_kba_renders_multi_option_string_answers_as_radios(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $portalToken = $service->resolveRawToken($issued['token']);
        $this->assertNotNull($portalToken);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_questions']
        );

        $sessionKey = 'portal_credit_check_questions_'.$lead->id.'_'.$portalToken->id;
        $this->withSession([
            $sessionKey => [[
                'id' => 'q-multi',
                'question' => 'Pick one option',
                'answers' => ['Option A', 'Option B', 'Option C'],
            ]],
        ])->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Option A')
            ->assertSee('Option B')
            ->assertSee('Option C')
            ->assertSee('type="radio"', false);
    }

    public function test_successful_credit_check_import_moves_progress_to_credit_report_debts_not_review(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        Http::fake([
            'http://listener.test/health' => Http::response([
                'ok' => true,
                'activeJob' => true,
            ], 200),
            'http://listener.test/jobs/portal-job-import/state' => Http::response([
                'ok' => true,
                'active' => true,
                'activeJob' => ['leadId' => $lead->id],
                'latestStatus' => ['data' => ['step' => 'report_ready']],
            ], 200),
            'http://listener.test/jobs/portal-job-import/questions' => Http::response(['payload' => []], 200),
            'http://listener.test/jobs/portal-job-import/report-data' => Http::response([
                'payload' => [
                    'debts' => [
                        ['creditor' => 'Demo Bank', 'balance' => '1234.56'],
                    ],
                ],
            ], 200),
            'http://listener.test/jobs/portal-job-import/pdf-state' => Http::response([
                'payload' => ['status' => 'moved_primary'],
            ], 200),
            'http://listener.test/jobs/portal-job-import/status' => Http::response(['ok' => true], 200),
            'http://listener.test/jobs/portal-job-import/complete' => Http::response(['ok' => true], 200),
        ]);

        Creditor::create([
            'name' => 'Demo Bank',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'postcode' => 'SW1A1AA',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-import',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now(),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_running']
        );

        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token']]))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'complete',
                'next_step' => 'credit_report_debts',
            ]);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertSame('credit_check', $progress->last_completed_step);
        $this->assertSame('credit_report_debts', $progress->current_step);

        $lead->refresh();
        $this->assertNotNull($lead->portal_credit_check_completed_at);
        $this->assertDatabaseHas('debts', [
            'lead_id' => $lead->id,
            'source_expected' => 'credit_check',
            'balance' => 1234.56,
        ]);
    }

    public function test_credit_report_debts_page_lists_imported_credit_check_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);

        $creditor = Creditor::create([
            'name' => 'Canonical Lender',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 789.45,
            'source_expected' => 'credit_check',
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'credit_check', 'current_step' => 'credit_report_debts']
        );

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Here&rsquo;s what appeared on your credit file', false)
            ->assertSee('Canonical Lender')
            ->assertSee('£789.45', false);
    }

    public function test_credit_report_debts_page_displays_cleaned_creditor_name(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);

        $creditor = Creditor::create([
            'name' => 'Could Not Match',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 321.00,
            'source_expected' => 'credit_check',
            'reference' => 'Raw creditor: Real Finance Co',
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'credit_check', 'current_step' => 'credit_report_debts']
        );

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Real Finance Co')
            ->assertDontSee('Could Not Match')
            ->assertDontSee('Raw creditor:', false);
    }

    public function test_credit_report_debts_page_shows_total_found(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);

        $creditor = Creditor::create([
            'name' => 'Balance Lender',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 100.50,
            'source_expected' => 'credit_check',
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 200,
            'source_expected' => 'credit_check',
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'credit_check', 'current_step' => 'credit_report_debts']
        );

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Total found')
            ->assertSee('£300.50', false);
    }

    public function test_credit_report_debts_page_shows_no_debts_state_if_none_imported(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'credit_check', 'current_step' => 'credit_report_debts']
        );

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('We couldn&rsquo;t see any balances from the credit check, but you can still add anything you know about next.', false);
    }

    public function test_credit_report_debts_continue_route_advances_to_add_missing_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'credit_check', 'current_step' => 'credit_report_debts']
        );

        $this->post(route('portal.credit-report-debts.continue', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_report_debts', $progress->last_completed_step);
        $this->assertSame('add_missing_debts', $progress->current_step);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Is anything missing?')
            ->assertSee('If you can&rsquo;t find the creditor, choose Other.', false);
    }

    public function test_invalid_or_expired_token_cannot_continue_credit_report_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('credit-report-debts-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        foreach (['not-a-real-token', 'credit-report-debts-expired-token'] as $rawToken) {
            $this->post(route('portal.credit-report-debts.continue', ['token' => $rawToken]))
                ->assertStatus(410)
                ->assertSee('This link is no longer active');
        }
    }

    public function test_unverified_session_cannot_continue_credit_report_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.credit-report-debts.continue', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertNull(LeadPortalProgress::where('lead_id', $lead->id)->first());
    }

    public function test_add_missing_debts_page_renders_searchable_creditor_ui_and_other_option(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);

        Creditor::create([
            'name' => 'Dropdown Lender',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Is anything missing?')
            ->assertSee('name="debts[0][creditor_id]"', false)
            ->assertSee('Start typing creditor name...')
            ->assertSee('Dropdown Lender')
            ->assertSee('Other')
            ->assertSee('Other creditor name (optional)')
            ->assertSee('Only use this if you can&rsquo;t find the creditor in the search.', false)
            ->assertSee('Add another debt');
    }

    public function test_customer_can_skip_missing_debts_and_progress_moves_to_iva_results(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => '', 'creditor_name' => '', 'balance' => ''],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertSame(0, Debt::where('lead_id', $lead->id)->count());
        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertSame('add_missing_debts', $progress->last_completed_step);
        $this->assertSame('iva_results', $progress->current_step);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Here&rsquo;s what this could mean', false)
            ->assertSee('Based on the figures so far, an IVA may not be the best fit, but we can still help you understand your options.');
    }

    public function test_selected_creditor_missing_debt_is_saved_to_canonical_debts_table(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $creditor = $this->createCreditor('Selected Lender');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '456.78'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertDatabaseHas('debts', [
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'source_expected' => 'customer_added',
            'balance' => 456.78,
            'reference' => null,
        ]);
    }

    public function test_other_free_text_debt_is_saved_using_could_not_match_creditor_and_reference(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $couldNotMatch = $this->createCreditor('Could Not Match');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => 'other', 'creditor_name' => 'Council Tax', 'balance' => '300'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertDatabaseHas('debts', [
            'lead_id' => $lead->id,
            'creditor_id' => $couldNotMatch->id,
            'source_expected' => 'customer_added',
            'balance' => 300,
            'reference' => 'Council Tax',
        ]);
    }

    public function test_blank_missing_debt_rows_are_ignored(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $creditor = $this->createCreditor('One Real Lender');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => '', 'creditor_name' => '', 'balance' => ''],
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '50'],
                ['creditor_id' => '', 'creditor_name' => '', 'balance' => ''],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertSame(1, Debt::where('lead_id', $lead->id)->where('source_expected', 'customer_added')->count());
    }

    public function test_dynamic_missing_debt_rows_allow_submitting_more_than_three_rows(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $creditor = $this->createCreditor('Dynamic Row Lender');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => '', 'creditor_name' => '', 'balance' => ''],
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '10'],
                ['creditor_id' => '', 'creditor_name' => '', 'balance' => ''],
                ['creditor_id' => 'other', 'creditor_name' => 'Council Tax', 'balance' => '20'],
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '30'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertSame(3, Debt::where('lead_id', $lead->id)->where('source_expected', 'customer_added')->count());
    }

    public function test_missing_debt_row_with_balance_but_no_creditor_returns_friendly_error(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
                'debts' => [
                    ['creditor_id' => '', 'creditor_name' => '', 'balance' => '25'],
                ],
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'missing_debts' => 'Please choose a creditor, or choose Other and enter the name.',
            ]);
    }

    public function test_missing_debt_row_with_creditor_but_no_balance_returns_friendly_error(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $creditor = $this->createCreditor('No Balance Lender');

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
                'debts' => [
                    ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => ''],
                ],
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors([
                'missing_debts' => 'Please enter a balance for each creditor you add.',
            ]);
    }

    public function test_missing_debts_save_ignores_tampered_lead_id(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $otherLead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $creditor = $this->createCreditor('Tamper Lender');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'lead_id' => $otherLead->id,
            'debts' => [
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '75'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertSame(1, Debt::where('lead_id', $lead->id)->where('source_expected', 'customer_added')->count());
        $this->assertSame(0, Debt::where('lead_id', $otherLead->id)->count());
    }

    public function test_saved_missing_debts_use_customer_added_source_and_create_debt_document(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnAddMissingDebtsStep($lead);
        $creditor = $this->createCreditor('Documented Lender');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '125'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $debt = Debt::where('lead_id', $lead->id)->where('source_expected', 'customer_added')->first();
        $this->assertNotNull($debt);
        $this->assertDatabaseHas('debt_documents', [
            'debt_id' => $debt->id,
            'proof_type' => 'customer_added',
            'is_complete' => false,
        ]);
    }

    public function test_existing_staff_debt_route_still_works_with_same_response_shape(): void
    {
        $user = User::factory()->create();
        $lead = $this->makeLead();
        $creditor = $this->createCreditor('Staff Route Lender');

        $this->actingAs($user)
            ->postJson('/lead/'.$lead->id.'/debts', [
                'creditor_id' => $creditor->id,
                'balance' => '999.99',
                'source_expected' => 'credit_check',
                'reference' => 'staff-ref',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'debt' => [
                    'creditor_name' => 'Staff Route Lender',
                    'creditor_id' => $creditor->id,
                    'balance' => '999.99',
                    'source_expected' => 'credit_check',
                    'reference' => 'staff-ref',
                    'document_complete' => true,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'debt' => [
                    'id',
                    'creditor_name',
                    'creditor_id',
                    'balance',
                    'source_expected',
                    'reference',
                    'voting_house',
                    'voting_practice1',
                    'voting_practice2',
                    'voting_practice3',
                    'document_complete',
                ],
            ]);
    }

    public function test_invalid_or_expired_token_cannot_save_missing_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('missing-debts-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        foreach (['not-a-real-token', 'missing-debts-expired-token'] as $rawToken) {
            $this->post(route('portal.missing-debts.save', ['token' => $rawToken]), [
                'debts' => [],
            ])
                ->assertStatus(410)
                ->assertSee('This link is no longer active');
        }
    }

    public function test_unverified_session_cannot_save_missing_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $creditor = $this->createCreditor('Unverified Lender');

        $this->post(route('portal.missing-debts.save', ['token' => $issued['token']]), [
            'debts' => [
                ['creditor_id' => (string) $creditor->id, 'creditor_name' => '', 'balance' => '100'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertSame(0, Debt::where('lead_id', $lead->id)->count());
        $this->assertNull(LeadPortalProgress::where('lead_id', $lead->id)->first());
    }

    public function test_iva_results_shows_potential_write_off_estimate_when_write_off_is_meaningful(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnIvaResultsStep($lead);
        $creditor = $this->createCreditor('Iva Threshold Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 10000,
            'source_expected' => 'credit_check',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Here&rsquo;s what this could mean', false)
            ->assertSee('£10,000.00', false)
            ->assertSee('Based on what we&rsquo;ve found so far, an IVA may be worth looking at.', false)
            ->assertSee('£6,000', false)
            ->assertSee('£4,000.00', false)
            ->assertSee('Continue to summary');
    }

    public function test_iva_results_over_threshold_with_small_write_off_uses_pressure_control_message(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnIvaResultsStep($lead);
        $creditor = $this->createCreditor('Small Write Off Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 6500,
            'source_expected' => 'credit_check',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('£6,500.00', false)
            ->assertSee('The biggest benefit may not be the amount written off', false)
            ->assertSee('subject to assessment', false)
            ->assertDontSee('that could mean around £500.00 may not need to be repaid', false);
    }

    public function test_iva_results_avoids_iva_pitch_when_total_debt_below_threshold(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnIvaResultsStep($lead);
        $creditor = $this->createCreditor('Low Balance Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 5999.99,
            'source_expected' => 'customer_added',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('£5,999.99', false)
            ->assertSee('Based on the figures so far, an IVA may not be the best fit, but we can still help you understand your options.')
            ->assertDontSee('Based on what we&rsquo;ve found so far, an IVA may be worth looking at.', false);
    }

    public function test_iva_results_ccj_debt_triggers_enforcement_note(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnIvaResultsStep($lead);
        $creditor = $this->createCreditor('County Court Judgment');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 7000,
            'source_expected' => 'credit_check',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('If court action or bailiffs are a concern, it&rsquo;s important to speak to someone quickly. An approved solution may help stop further enforcement.', false);
    }

    public function test_iva_results_total_debt_includes_credit_check_and_customer_added_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnIvaResultsStep($lead);
        $creditor = $this->createCreditor('Combined Debt Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 4500,
            'source_expected' => 'credit_check',
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 2500,
            'source_expected' => 'customer_added',
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 999,
            'source_expected' => 'other',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('£7,000.00', false)
            ->assertSee('£1,000.00', false)
            ->assertDontSee('£7,999.00', false);
    }

    public function test_iva_results_continue_route_advances_to_review(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);
        $this->verifyPortalSession($issued['token']);
        $this->putPortalOnIvaResultsStep($lead);

        $this->post(route('portal.iva-results.continue', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('iva_results', $progress->last_completed_step);
        $this->assertSame('review', $progress->current_step);
    }

    public function test_invalid_or_expired_token_cannot_continue_iva_results(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        LeadPortalToken::create([
            'lead_id' => $lead->id,
            'token_hash' => $service->hashRawToken('iva-results-expired-token'),
            'status' => LeadPortalToken::STATUS_ACTIVE,
            'activated_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        foreach (['not-a-real-token', 'iva-results-expired-token'] as $rawToken) {
            $this->post(route('portal.iva-results.continue', ['token' => $rawToken]))
                ->assertStatus(410)
                ->assertSee('This link is no longer active');
        }
    }

    public function test_unverified_session_cannot_continue_iva_results(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.iva-results.continue', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $this->assertNull(LeadPortalProgress::where('lead_id', $lead->id)->first());
    }

    public function test_portal_poll_with_listener_unavailable_moves_to_credit_check_failed(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/health' => Http::response(['ok' => false], 503),
            'http://listener.test/jobs/portal-job-poll-fail/state' => Http::response([], 503),
            'http://listener.test/jobs/portal-job-poll-fail/questions' => Http::response([], 503),
            'http://listener.test/jobs/portal-job-poll-fail/report-data' => Http::response([], 503),
            'http://listener.test/jobs/portal-job-poll-fail/pdf-state' => Http::response([], 503),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-poll-fail',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now()->subMinutes(1),
        ]);

        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token']]))
            ->assertOk()
            ->assertJson([
                'status' => 'failed',
            ]);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_failed', $progress->current_step);
    }

    public function test_portal_poll_with_active_job_older_than_timeout_moves_to_credit_check_failed(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        config()->set('services.credit_check_v3_listener.portal_running_timeout_seconds', 60);
        Http::fake([
            'http://listener.test/health' => Http::response(['ok' => true, 'activeJob' => true], 200),
            'http://listener.test/jobs/portal-job-timeout/state' => Http::response([
                'ok' => true,
                'active' => true,
                'activeJob' => ['leadId' => 1],
                'latestStatus' => ['data' => ['step' => 'running']],
            ], 200),
            'http://listener.test/jobs/portal-job-timeout/questions' => Http::response(['payload' => ['questions' => []]], 200),
            'http://listener.test/jobs/portal-job-timeout/report-data' => Http::response(['payload' => []], 200),
            'http://listener.test/jobs/portal-job-timeout/pdf-state' => Http::response(['payload' => ['status' => 'pending']], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-timeout',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now()->subMinutes(5),
        ]);

        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token']]))
            ->assertOk()
            ->assertJson([
                'status' => 'failed',
            ]);

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_failed', $progress->current_step);
    }

    public function test_repeated_portal_poll_does_not_duplicate_imported_debts(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        Http::fake([
            'http://listener.test/health' => Http::response(['ok' => true, 'activeJob' => true], 200),
            'http://listener.test/jobs/portal-job-repeat/state' => Http::response([
                'ok' => true,
                'active' => true,
                'activeJob' => ['leadId' => $lead->id],
                'latestStatus' => ['data' => ['step' => 'report_ready']],
            ], 200),
            'http://listener.test/jobs/portal-job-repeat/questions' => Http::response(['payload' => []], 200),
            'http://listener.test/jobs/portal-job-repeat/report-data' => Http::response([
                'payload' => [
                    'debts' => [
                        ['creditor' => 'Repeat Bank', 'balance' => '500'],
                    ],
                ],
            ], 200),
            'http://listener.test/jobs/portal-job-repeat/pdf-state' => Http::response([
                'payload' => ['status' => 'moved_primary'],
            ], 200),
            'http://listener.test/jobs/portal-job-repeat/status' => Http::response(['ok' => true], 200),
            'http://listener.test/jobs/portal-job-repeat/complete' => Http::response(['ok' => true], 200),
        ]);

        Creditor::create([
            'name' => 'Repeat Bank',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-repeat',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Running',
            'started_at' => now(),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_running']
        );

        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token']]))->assertOk();
        $this->getJson(route('portal.credit-check.poll', ['token' => $issued['token']]))->assertOk();

        $this->assertSame(1, Debt::where('lead_id', $lead->id)->where('source_expected', 'credit_check')->count());
    }

    public function test_portal_credit_check_answers_submit_calls_shared_flow_and_returns_to_running(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/portal-job-answers/answers' => Http::response([
                'ok' => true,
                'message' => 'Answers accepted',
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-answers',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Awaiting verification',
            'started_at' => now(),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_questions']
        );

        $this->postJson(route('portal.credit-check.answers', [
            'token' => $issued['token'],
            'lead_id' => 999,
            'job_id' => 'tampered',
        ]), [
            'answers' => [
                ['id' => 'q1', 'index' => 0, 'value' => 'yes', 'label' => 'Yes'],
            ],
        ])->assertOk()
            ->assertJson([
                'ok' => true,
                'running' => true,
            ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $request->url() === 'http://listener.test/jobs/portal-job-answers/answers'
                && ($body['answers'][0]['id'] ?? null) === 'q1'
                && ($body['answers'][0]['index'] ?? null) === 0
                && ($body['answers'][0]['value'] ?? null) === 'yes'
                && ($body['answers'][0]['label'] ?? null) === 'Yes';
        });

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_running', $progress->current_step);
    }

    public function test_non_ajax_kba_submit_redirects_to_entry_and_sets_running_step(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/portal-job-answers-form/answers' => Http::response([
                'ok' => true,
                'message' => 'Answers accepted',
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-answers-form',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Awaiting verification',
            'started_at' => now(),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_questions']
        );

        $this->post(route('portal.credit-check.answers', ['token' => $issued['token']]), [
            'answers' => [
                ['id' => 'q1', 'index' => 0, 'value' => 'yes', 'label' => 'Yes'],
            ],
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check_running', $progress->current_step);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('We&rsquo;re checking your information', false);
    }

    public function test_invalid_or_unverified_portal_token_cannot_submit_credit_check_answers(): void
    {
        $this->postJson(route('portal.credit-check.answers', ['token' => 'invalid-token']), [
            'answers' => [['id' => 'q1', 'value' => 'Blue']],
        ])->assertStatus(410);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead();
        $issued = $service->issueForLead($lead);

        $this->postJson(route('portal.credit-check.answers', ['token' => $issued['token']]), [
            'answers' => [['id' => 'q1', 'value' => 'Blue']],
        ])->assertStatus(403);
    }

    public function test_missing_kba_radio_answer_returns_validation_error(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-answers-missing',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Awaiting verification',
            'started_at' => now(),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_questions']
        );

        $this->postJson(route('portal.credit-check.answers', ['token' => $issued['token']]), [
            'answers' => [
                ['id' => 'q1', 'index' => 0, 'value' => '', 'label' => ''],
            ],
        ])->assertStatus(422);
    }

    public function test_missing_kba_answer_non_ajax_redirects_back_with_friendly_error(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);

        $this->post(route('portal.verify', ['token' => $issued['token']]), [
            'dob' => '1985-06-15',
        ])->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'external_job_id' => 'portal-job-answers-missing-form',
            'status' => CreditCheckJobLog::STATUS_RUNNING,
            'friendly_status' => 'Awaiting verification',
            'started_at' => now(),
        ]);

        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['current_step' => 'credit_check_questions']
        );

        $this->from(route('portal.entry', ['token' => $issued['token']]))
            ->post(route('portal.credit-check.answers', ['token' => $issued['token']]), [
                'answers' => [
                    ['id' => 'q1', 'index' => 0, 'value' => '', 'label' => ''],
                ],
            ])
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]))
            ->assertSessionHasErrors(['credit_check']);
    }

    public function test_credit_check_start_advances_progress_to_credit_check_running(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-running-step',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $progress = LeadPortalProgress::where('lead_id', $lead->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame('credit_check', $progress->last_completed_step);
        $this->assertSame('credit_check_running', $progress->current_step);
    }

    public function test_second_credit_check_start_does_not_reset_timestamps(): void
    {
        config()->set('services.credit_check_v3_listener.base_url', 'http://listener.test');
        Http::fake([
            'http://listener.test/jobs/start' => Http::response([
                'ok' => true,
                'jobId' => 'portal-job-second-start',
                'queued' => false,
            ], 200),
        ]);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteWelcomeDetailsDebtsIncomeAndCosts($issued['token']);

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertRedirect(route('portal.entry', ['token' => $issued['token']]));

        $lead->refresh();
        $firstStartedAt = $lead->portal_credit_check_started_at?->copy();
        $firstLastRunAt = $lead->portal_credit_check_last_run_at?->copy();

        $this->post(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
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

        $this->postJson(route('portal.credit-check.v3.start', ['token' => $issued['token']]))
            ->assertStatus(403);

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

        $this->postJson(route('portal.credit-check.v3.start', ['token' => 'credit-check-expired-token']))
            ->assertStatus(410);
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
            ->assertSee('Your debts')
            ->assertSee('What this could mean')
            ->assertSee('Monthly picture');
    }

    public function test_review_page_shows_unmasked_personal_details(): void
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
            ->assertSee('Name: Alice Baker')
            ->assertSee('Postcode: SW1A 1AA')
            ->assertSee('Phone: 07000000000');
    }

    public function test_review_page_lists_credit_check_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToReview($issued['token']);

        $creditor = Creditor::create([
            'name' => 'Canonical Lender',
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 789.45,
            'source_expected' => 'credit_check',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Canonical Lender')
            ->assertSee('£789.45')
            ->assertSee('Credit check');
    }

    public function test_review_page_lists_customer_added_debts(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToReview($issued['token']);
        $creditor = $this->createCreditor('Added Debt Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 250.25,
            'source_expected' => 'customer_added',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Added Debt Lender')
            ->assertSee('£250.25')
            ->assertSee('Added by you');
    }

    public function test_review_uses_cleaned_creditor_display_for_could_not_match(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToReview($issued['token']);

        $couldNotMatch = $this->createCreditor('Could Not Match');
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $couldNotMatch->id,
            'balance' => 111.11,
            'source_expected' => 'customer_added',
            'reference' => 'Raw creditor: Real Lender Ltd',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Real Lender Ltd')
            ->assertDontSee('Could Not Match');
    }

    public function test_review_shows_total_debt_for_canonical_rows(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToReview($issued['token']);
        $creditor = $this->createCreditor('Total Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 300,
            'source_expected' => 'credit_check',
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 200,
            'source_expected' => 'customer_added',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Total debt')
            ->assertSee('£500.00', false);
    }

    public function test_review_shows_iva_estimate_when_eligible(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToReview($issued['token']);
        $creditor = $this->createCreditor('Eligible Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 9000,
            'source_expected' => 'credit_check',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('What this could mean')
            ->assertSee('Estimated total debt: £9,000.00', false)
            ->assertSee('Example repayment total: £6,000.00', false)
            ->assertSee('Potential write-off: £3,000.00', false);
    }

    public function test_review_eligible_with_small_write_off_uses_non_writeoff_framing(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToReview($issued['token']);
        $creditor = $this->createCreditor('Small Review Write Off Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 6100,
            'source_expected' => 'credit_check',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('The biggest benefit may not be the amount written off', false)
            ->assertDontSee('Potential write-off: £100.00', false);
    }

    public function test_review_shows_sub_threshold_message_when_not_eligible(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToReview($issued['token']);
        $creditor = $this->createCreditor('Not Eligible Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 5000,
            'source_expected' => 'customer_added',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('Based on the figures so far, an IVA may not be the best fit, but we can still help discuss your options.');
    }

    public function test_review_shows_ccj_enforcement_note_when_relevant(): void
    {
        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['dob' => '1985-06-15']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToReview($issued['token']);
        $creditor = $this->createCreditor('Any Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 7000,
            'source_expected' => 'credit_check',
            'reference' => 'CCJ reference 123',
        ]);

        $this->get(route('portal.entry', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('If court action or bailiffs are a concern, it&rsquo;s important to speak to someone quickly. An approved solution may help stop further enforcement.', false);
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
            ->assertSee('Want help understanding this?', false)
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
        config(['services.portal.whatsapp_url' => 'https://wa.me/441234567890']);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => 'portal@example.com',
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);

        $this->post(route('portal.complete', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('You&rsquo;re all set', false)
            ->assertSee('Message us on WhatsApp');

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
        config(['services.portal.whatsapp_url' => 'https://wa.me/441234567890']);

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'dob' => '1985-06-15',
            'email' => null,
        ]);
        $issued = $service->issueForLead($lead);

        $this->verifyThenCompleteToCompletePending($issued['token']);
        $this->post(route('portal.complete', ['token' => $issued['token']]))
            ->assertOk()
            ->assertSee('We&rsquo;ve saved your summary. If you&rsquo;d like help understanding what this means, message us now.', false);

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
        $creditor = $this->createCreditor('Example Lender');
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => '1234.56',
            'source_expected' => 'credit_check',
        ]);
        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $snapshot = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->first();
        $this->assertNotNull($snapshot);
        $json = $snapshot->snapshot_json;
        $this->assertIsArray($json);
        $this->assertArrayHasKey('details', $json);
        $this->assertArrayHasKey('debts', $json);
        $this->assertArrayHasKey('totals', $json);
        $this->assertArrayHasKey('iva_estimate', $json);
        $this->assertArrayHasKey('financial_summary', $json);
        $this->assertArrayHasKey('income', $json);
        $this->assertArrayHasKey('costs', $json);
        $this->assertArrayHasKey('credit_check', $json);
        $this->assertSame('Alice', $json['details']['first_name'] ?? null);
        $this->assertSame('Baker', $json['details']['last_name'] ?? null);
        $this->assertArrayNotHasKey('dob', $json['details']);
        $this->assertSame('1234.56', (string) ($json['totals']['total_debt'] ?? ''));
        $this->assertSame('Employed full-time', $json['income']['employment_status'] ?? null);
        $this->assertSame('2000.00', (string) ($json['income']['monthly_income'] ?? ''));
        $this->assertSame('900.00', (string) ($json['costs']['monthly_housing_cost'] ?? ''));
        $this->assertSame('100.00', (string) ($json['costs']['monthly_council_tax'] ?? ''));
        $this->assertSame('150.00', (string) ($json['costs']['monthly_utilities_cost'] ?? ''));
        $this->assertSame('300.00', (string) ($json['costs']['monthly_food_travel_cost'] ?? ''));
        $this->assertNotNull($json['credit_check']['portal_credit_check_started_at'] ?? null);
        $this->assertNotNull($json['credit_check']['portal_credit_check_last_run_at'] ?? null);
        $this->assertSame('Example Lender', $json['debts'][0]['creditor_name_customer'] ?? null);
        $this->assertSame('Example Lender', $json['debts'][0]['creditor_name_backend'] ?? null);
        $this->assertSame('Credit check', $json['debts'][0]['source_label'] ?? null);
    }

    public function test_snapshot_contains_all_canonical_credit_check_and_customer_added_debts_with_total_and_iva_estimate(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['email' => 'portal@example.com']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $creditor = $this->createCreditor('Snapshot Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 4000,
            'source_expected' => 'credit_check',
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 3000,
            'source_expected' => 'customer_added',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $json = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->firstOrFail()->snapshot_json;
        $this->assertCount(2, $json['debts']);
        $this->assertSame(['Credit check', 'Added by you'], array_column($json['debts'], 'source_label'));
        $this->assertSame('7000', (string) ($json['totals']['total_debt'] ?? ''));
        $this->assertSame('7000', (string) ($json['iva_estimate']['total_debt'] ?? ''));
        $this->assertTrue($json['iva_estimate']['is_eligible'] ?? false);
    }

    public function test_snapshot_uses_cleaned_customer_facing_creditor_name_for_unmatched_debt(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['email' => 'portal@example.com']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $couldNotMatch = $this->createCreditor('Could Not Match');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $couldNotMatch->id,
            'balance' => 2500,
            'source_expected' => 'credit_check',
            'reference' => 'Raw creditor: Clean Snapshot Lender',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        $debt = LeadPortalSnapshot::where('lead_id', $lead->id)->latest('id')->firstOrFail()->snapshot_json['debts'][0];
        $this->assertSame('Clean Snapshot Lender', $debt['creditor_name_customer'] ?? null);
        $this->assertSame('Could Not Match', $debt['creditor_name_backend'] ?? null);
    }

    public function test_summary_email_content_includes_snapshot_financial_sections_and_omits_full_dob(): void
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
        $creditor = $this->createCreditor('Example Lender');
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => '1234.56',
            'source_expected' => 'credit_check',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Here&rsquo;s the summary we put together from the details you provided.')
                && str_contains($html, 'Customer details')
                && str_contains($html, 'Alice Baker')
                && str_contains($html, 'SW1A 1AA')
                && str_contains($html, 'Downing Street')
                && str_contains($html, 'Total debt:')
                && str_contains($html, 'Example Lender')
                && str_contains($html, 'Monthly picture')
                && str_contains($html, '£1,234.56')
                && str_contains($html, '£2,000.00')
                && ! str_contains($html, '1985-06-15');
        });
    }

    public function test_summary_email_lists_credit_check_and_customer_added_debts_with_cleaned_names(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead([
            'first_name' => 'Alice',
            'last_name' => 'Baker',
            'email' => 'portal@example.com',
            'postcode' => 'SW1A 1AA',
        ]);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $creditor = $this->createCreditor('Imported Email Lender');
        $couldNotMatch = $this->createCreditor('Could Not Match');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 1000,
            'source_expected' => 'credit_check',
        ]);
        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $couldNotMatch->id,
            'balance' => 2000,
            'source_expected' => 'customer_added',
            'reference' => 'Raw creditor: Customer Email Lender',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Imported Email Lender')
                && str_contains($html, 'Customer Email Lender')
                && str_contains($html, 'Credit check')
                && str_contains($html, 'Added by you')
                && str_contains($html, '£3,000.00')
                && ! str_contains($html, 'Could Not Match')
                && ! str_contains($html, 'SW1****');
        });
    }

    public function test_summary_email_includes_iva_estimate_when_total_debt_meets_threshold(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['email' => 'portal@example.com']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $creditor = $this->createCreditor('Iva Email Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 9000,
            'source_expected' => 'credit_check',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Based on what we&rsquo;ve found so far, an IVA may be worth looking at.')
                && str_contains($html, '£6,000')
                && str_contains($html, '£9,000.00')
                && str_contains($html, '£3,000.00');
        });
    }

    public function test_summary_email_includes_sub_threshold_message_when_total_debt_below_threshold(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['email' => 'portal@example.com']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $creditor = $this->createCreditor('Low Email Lender');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 5000,
            'source_expected' => 'customer_added',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Based on the figures so far, an IVA may not be the best fit, but you can still ask for help understanding your options.')
                && ! str_contains($html, 'Based on what we&rsquo;ve found so far, an IVA may be worth looking at.');
        });
    }

    public function test_summary_email_includes_court_judgment_note_when_snapshot_flags_it(): void
    {
        Mail::fake();

        $service = app(LeadPortalTokenService::class);
        $lead = $this->makeLead(['email' => 'portal@example.com']);
        $issued = $service->issueForLead($lead);
        $this->verifyThenCompleteToCompletePending($issued['token']);
        $creditor = $this->createCreditor('County Court Judgment');

        Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => 7000,
            'source_expected' => 'credit_check',
        ]);

        $this->post(route('portal.complete', ['token' => $issued['token']]))->assertOk();

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail): bool {
            return str_contains(
                $mail->render(),
                'We&rsquo;ve also seen court judgment information, so it may be important to get advice before enforcement escalates.'
            );
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

        $snapshot = LeadPortalSnapshot::where('lead_id', $leadWithCta->id)->latest('id')->firstOrFail();
        $expectedTrackingUrl = route('portal.summary.click', ['snapshot' => $snapshot->id, 'type' => 'whatsapp']);

        Mail::assertSent(LeadPortalSummaryMail::class, function (LeadPortalSummaryMail $mail) use ($expectedTrackingUrl): bool {
            $html = $mail->render();

            return str_contains($html, 'Message us on WhatsApp')
                && str_contains($html, $expectedTrackingUrl)
                && ! str_contains($html, 'href="https://wa.me/');
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
            'title' => 'Mr',
            'first_name' => 'Portal',
            'last_name' => 'Tester',
            'phone_number' => '07000000000',
            'postcode' => 'SW1A 1AA',
            'house_number' => '10',
        ], $overrides));
    }

    private function verifyPortalSession(string $rawToken): void
    {
        $this->post(route('portal.verify', ['token' => $rawToken]), [
            'postcode' => 'SW1A1AA',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }

    private function putPortalOnAddMissingDebtsStep(Lead $lead): void
    {
        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'credit_report_debts', 'current_step' => 'add_missing_debts', 'last_seen_at' => now()]
        );
    }

    private function putPortalOnIvaResultsStep(Lead $lead): void
    {
        LeadPortalProgress::updateOrCreate(
            ['lead_id' => $lead->id],
            ['last_completed_step' => 'add_missing_debts', 'current_step' => 'iva_results', 'last_seen_at' => now()]
        );
    }

    private function createCreditor(string $name): Creditor
    {
        return Creditor::create([
            'name' => $name,
            'voting_house' => 'House',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);
    }

    private function verifyThenCompleteWelcomeAndDetails(string $rawToken): void
    {
        $this->post(route('portal.verify', ['token' => $rawToken]), [
            'postcode' => 'SW1A1AA',
        ])->assertRedirect(route('portal.entry', ['token' => $rawToken]));

        $this->post(route('portal.welcome.complete', ['token' => $rawToken]))
            ->assertRedirect(route('portal.entry', ['token' => $rawToken]));

        $this->post(route('portal.details.save', ['token' => $rawToken]), [
            'title' => 'Mr',
            'first_name' => 'Alex',
            'last_name' => 'Stone',
            'dob' => '1985-06-15',
            'phone' => '07123456789',
            'postcode' => 'SW1A 1AA',
            'house_number' => '10',
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
        $tokenService = app(LeadPortalTokenService::class);
        $portalToken = $tokenService->resolveRawToken($rawToken);
        $leadId = $portalToken?->lead_id;
        if ($leadId) {
            LeadPortalProgress::updateOrCreate(
                ['lead_id' => $leadId],
                ['last_completed_step' => 'credit_check', 'current_step' => 'review', 'last_seen_at' => now()]
            );
        }
    }

    private function verifyThenCompleteToCompletePending(string $rawToken): void
    {
        $this->verifyThenCompleteToReview($rawToken);

        $this->post(route('portal.review.finish', ['token' => $rawToken]))
            ->assertRedirect(route('portal.entry', ['token' => $rawToken]));
    }
}
