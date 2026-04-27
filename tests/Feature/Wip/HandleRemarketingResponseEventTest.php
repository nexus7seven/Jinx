<?php

namespace Tests\Feature\Wip;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingResponseEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleRemarketingResponseEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_ignore_marks_event_without_stopping_progress_or_changing_wip(): void
    {
        $user = User::factory()->create();
        $lead = Lead::query()->create([
            'vicidial_lead_id' => 7576429,
            'phone_number' => '07700900123',
            'first_name' => 'Alex',
            'last_name' => 'Hurrell',
            'wip_status' => 'Lost Contact',
        ]);

        $progress = LeadRemarketingProgress::query()->create([
            'lead_id' => 7576429,
            'current_step_id' => null,
            'current_step_order' => null,
            'status' => 'active',
            'started_at' => now()->subHour(),
            'last_step_completed_at' => null,
            'next_step_due_at' => now(),
            'stopped_at' => null,
            'stop_reason' => null,
            'stop_context_json' => null,
        ]);

        $event = RemarketingResponseEvent::query()->create([
            'lead_id' => 7576429,
            'jinx_lead_id' => $lead->id,
            'remarketing_progress_id' => $progress->id,
            'source_event_id' => 'test-ignore-1',
            'dedupe_key' => 'test-ignore-1',
            'channel' => 'whatsapp',
            'direction' => 'inbound',
            'status' => RemarketingResponseEvent::STATUS_NEEDS_REVIEW,
            'message_preview' => 'hello',
            'detected_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user)
            ->post(route('remarketing.response.handle', ['id' => $event->id]), [
                'decision' => 'ignore',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('remarketing_response_events', [
            'id' => $event->id,
            'status' => RemarketingResponseEvent::STATUS_IGNORED,
            'decision' => 'ignore',
        ]);

        $this->assertDatabaseHas('lead_remarketing_progress', [
            'id' => $progress->id,
            'status' => 'active',
            'stopped_at' => null,
        ]);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'wip_status' => 'Lost Contact',
        ]);
    }
}
