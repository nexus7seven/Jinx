<?php

namespace Tests\Feature\Webhooks;

use App\Models\Lead;
use App\Models\RemarketingResponseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwilioInboundSmsWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_from_returns_422(): void
    {
        $response = $this->post('/webhooks/twilio/inbound-sms', [
            'MessageSid' => 'SM_MISSING_FROM_001',
            'Body' => 'Hello',
        ]);

        $response->assertStatus(422);
    }

    public function test_unmatched_phone_returns_200_skipped(): void
    {
        $response = $this->post('/webhooks/twilio/inbound-sms', [
            'From' => '+447700900123',
            'MessageSid' => 'SM_UNMATCHED_001',
            'Body' => 'Hi there',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'lead_not_found',
            ]);

        $this->assertDatabaseCount('remarketing_response_events', 0);
    }

    public function test_duplicate_message_sid_does_not_create_second_event(): void
    {
        $lead = Lead::query()->create([
            'vicidial_lead_id' => 7576429,
            'phone_number' => '07700900123',
            'first_name' => 'Alex',
            'last_name' => 'Hurrell',
            'wip_status' => 'Lost Contact',
        ]);

        $payload = [
            'From' => '+447700900123',
            'MessageSid' => 'SM_DUPLICATE_001',
            'Body' => 'Please call me back',
        ];

        $this->post('/webhooks/twilio/inbound-sms', $payload)
            ->assertOk()
            ->assertJson(['status' => 'created']);

        $this->post('/webhooks/twilio/inbound-sms', $payload)
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('remarketing_response_events', 1);
        $this->assertDatabaseHas('remarketing_response_events', [
            'lead_id' => 7576429,
            'jinx_lead_id' => $lead->id,
            'source_event_id' => 'SM_DUPLICATE_001',
            'dedupe_key' => 'sms:SM_DUPLICATE_001',
            'channel' => 'sms',
            'status' => 'needs_review',
        ]);
    }

    public function test_eligible_lost_contact_lead_creates_needs_review_event(): void
    {
        $lead = Lead::query()->create([
            'vicidial_lead_id' => 8880001,
            'phone_number' => '447700900555',
            'first_name' => 'Casey',
            'last_name' => 'Jones',
            'wip_status' => 'Lost Contact',
        ]);

        $this->post('/webhooks/twilio/inbound-sms', [
            'From' => '07700900555',
            'MessageSid' => 'SM_ELIGIBLE_001',
            'Body' => 'I want to continue',
        ])->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'created',
            ]);

        $this->assertDatabaseHas('remarketing_response_events', [
            'lead_id' => 8880001,
            'jinx_lead_id' => $lead->id,
            'dedupe_key' => 'sms:SM_ELIGIBLE_001',
            'status' => 'needs_review',
            'channel' => 'sms',
        ]);
    }

    public function test_active_wip_lead_does_not_create_event(): void
    {
        Lead::query()->create([
            'vicidial_lead_id' => 9990001,
            'phone_number' => '07700900999',
            'first_name' => 'Morgan',
            'last_name' => 'Lee',
            'wip_status' => 'Awaiting Call',
        ]);

        $this->post('/webhooks/twilio/inbound-sms', [
            'From' => '+447700900999',
            'MessageSid' => 'SM_INELIGIBLE_001',
            'Body' => 'Any update?',
        ])->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'lead_not_eligible',
            ]);

        $this->assertDatabaseMissing('remarketing_response_events', [
            'dedupe_key' => 'sms:SM_INELIGIBLE_001',
        ]);
    }
}
