<?php

namespace Tests\Feature\Wip;

use App\Models\Lead;
use App\Models\User;
use App\Services\WipCaseQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WipWorkingStackTest extends TestCase
{
    use RefreshDatabase;

    public function test_packager_can_persist_a_personal_working_stack_order(): void
    {
        $user = User::factory()->create();
        $first = $this->lead('First');
        $second = $this->lead('Second');
        $third = $this->lead('Third');

        $service = app(WipCaseQueueService::class);
        $initial = $service->sync($user, collect([$first, $second, $third]));
        $this->assertSame([$first->id, $second->id, $third->id], $initial->pluck('id')->all());

        $this->actingAs($user)->patchJson(route('wip.stack.reorder'), [
            'lead_ids' => [$third->id, $first->id, $second->id],
        ])->assertOk()->assertJsonPath('success', true);

        $ordered = $service->sync($user, collect([$first->fresh(), $second->fresh(), $third->fresh()]));
        $this->assertSame([$third->id, $first->id, $second->id], $ordered->pluck('id')->all());

        $this->assertDatabaseHas('wip_case_queue_items', [
            'user_id' => $user->id,
            'lead_id' => $third->id,
            'position' => 1000,
        ]);
    }

    public function test_actioned_case_records_waiting_state_and_moves_to_bottom(): void
    {
        $user = User::factory()->create();
        $first = $this->lead('First');
        $second = $this->lead('Second');
        $third = $this->lead('Third');

        $service = app(WipCaseQueueService::class);
        $service->sync($user, collect([$first, $second, $third]));

        $response = $this->actingAs($user)->postJson(route('lead.wip-actioned', ['lead' => $first->id]), [
            'waiting_on' => 'client',
            'next_chase_at' => null,
            'action_note' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('queue_item.waiting_on', 'client')
            ->assertJsonPath('queue_item.waiting_on_label', 'Client')
            ->assertJsonPath('queue_item.next_chase_at', null)
            ->assertJsonPath('queue_item.action_note', null);

        $ordered = $service->sync($user, collect([$first->fresh(), $second->fresh(), $third->fresh()]));
        $this->assertSame([$second->id, $third->id, $first->id], $ordered->pluck('id')->all());

        $this->assertDatabaseHas('wip_case_queue_items', [
            'user_id' => $user->id,
            'lead_id' => $first->id,
            'waiting_on' => 'client',
            'action_note' => null,
        ]);
    }

    public function test_new_case_enters_at_top_of_an_existing_working_stack(): void
    {
        $user = User::factory()->create();
        $first = $this->lead('First');
        $second = $this->lead('Second');

        $service = app(WipCaseQueueService::class);
        $service->sync($user, collect([$first, $second]));

        $newLead = $this->lead('Newest');

        $ordered = $service->sync($user, collect([$newLead, $first->fresh(), $second->fresh()]));
        $this->assertSame([$newLead->id, $first->id, $second->id], $ordered->pluck('id')->all());
    }

    public function test_wip_ui_has_stack_action_modal_and_non_blurring_assistant_drawer(): void
    {
        $index = file_get_contents(resource_path('views/wip/index.blade.php'));
        $assistant = file_get_contents(resource_path('views/wip/assistant.blade.php'));
        $card = file_get_contents(resource_path('views/wip/partials/lead-card.blade.php'));

        $this->assertStringContainsString('class="wip-stack-handle"', $card);
        $this->assertStringContainsString('id="wip-actioned-modal"', $index);
        $this->assertStringContainsString('Actioned ↓', $card);
        $this->assertStringContainsString('Move to bottom', $index);
        $this->assertStringContainsString('id="wip-actioned-waiting-on"', $index);
        $this->assertStringNotContainsString('id="wip-actioned-chase-preset"', $index);
        $this->assertStringNotContainsString('id="wip-actioned-note"', $index);
        $this->assertStringNotContainsString('scrollIntoView', $index);

        $this->assertStringContainsString('class="wip-board-layout"', $index);
        $this->assertStringContainsString('id="wip-dashboard-grid"', $index);
        $this->assertStringContainsString('data-wip-lane="priority"', $index);
        $this->assertStringContainsString('data-wip-lane="callbacks"', $index);
        $this->assertStringContainsString('data-wip-lane="without-callbacks"', $index);
        $this->assertStringContainsString('id="wip-next-sip-banner"', $index);
        $this->assertStringContainsString('id="wip-sip-modal"', $index);
        $this->assertStringContainsString('LIVE_SYNC_MS = 15000', $index);
        $this->assertStringNotContainsString('const IDLE_MS', $index);
        $this->assertStringContainsString('data-sip-prep-done', $card);
        $this->assertStringContainsString('data-callback-at=', $card);

        $this->assertStringContainsString('id="wipAssistantLauncher"', $assistant);
        $this->assertStringContainsString('.wip-assistant.is-open', $assistant);
        $this->assertStringNotContainsString('backdrop-filter', $assistant);
        $this->assertStringNotContainsString('wip-assistant-scrim', $assistant);
    }

    public function test_workdesk_lanes_partition_priority_callbacks_and_leads_without_callbacks(): void
    {
        $index = file_get_contents(resource_path('views/wip/index.blade.php'));

        $this->assertStringContainsString("['SIP Booked', 'Ready to Refer', 'DMP Transfer']", $index);
        $this->assertStringContainsString('$callbackLeads = $remainingLeads->filter', $index);
        $this->assertStringContainsString('$withoutCallbackLeads = $remainingLeads->reject', $index);
        $this->assertStringContainsString('id="wip-priority-stack"', $index);
        $this->assertStringContainsString('id="wip-callback-stack"', $index);
        $this->assertStringContainsString('id="wip-without-callback-stack"', $index);
        $this->assertStringNotContainsString('id="wip-dmp-stack"', $index);
        $this->assertStringNotContainsString('id="wip-other-stack"', $index);
        $this->assertStringContainsString("sortStack(document.getElementById('wip-callback-stack'), callbackRank)", $index);
        $this->assertStringContainsString("sortStack(document.getElementById('wip-without-callback-stack'), (card) => [20, queuePosition(card)])", $index);
    }

    public function test_redesigned_workdesk_has_one_header_and_only_actionable_card_metadata(): void
    {
        $index = file_get_contents(resource_path('views/wip/index.blade.php'));
        $card = file_get_contents(resource_path('views/wip/partials/lead-card.blade.php'));
        $css = file_get_contents(public_path('css/wip-workdesk.css'));

        $this->assertStringContainsString('<h1 class="wip-header-title">Workdesk</h1>', $index);
        $this->assertSame(1, substr_count($index, 'id="wip-live-indicator"'));
        $this->assertStringNotContainsString('<h2>Live workdesk</h2>', $index);
        $this->assertStringContainsString('css/wip-workdesk.css', $index);
        $this->assertStringContainsString('id="wip-dashboard-grid"', $index);
        $this->assertStringContainsString('id="wip-next-sip-banner"', $index);
        $this->assertStringNotContainsString('Created</span>', $card);
        $this->assertStringNotContainsString('Last dialled</span>', $card);
        $this->assertStringContainsString('data-callback-countdown', $card);
        $this->assertStringContainsString('data-sip-countdown', $card);
        $this->assertStringContainsString('data-sip-prep-done', $card);
        $this->assertStringContainsString('class="wip-stack-handle"', $card);
        $this->assertStringContainsString('class="status-select"', $card);
        $this->assertStringContainsString('Actioned ↓', $card);
        $this->assertStringContainsString('.wip-lane,.wip-other-statuses', $css);
        $this->assertStringContainsString('.wip-callback-strip{margin:0;padding:0;', $css);
    }

    public function test_sip_booked_requires_an_appointment_time(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead('SIP Missing');

        $this->actingAs($user)->patchJson(route('lead.wip-status', ['lead' => $lead->id]), [
            'wip_status' => 'SIP Booked',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('Collecting Docs', $lead->fresh()->wip_status);
        $this->assertNull($lead->fresh()->sip_booked_at);
    }

    public function test_sip_time_is_stored_and_prep_call_can_be_completed(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead('SIP Timed');
        $when = now()->addHour()->startOfMinute();
        $browserIso = $when->copy()->utc()->toIso8601String();

        $this->actingAs($user)->patchJson(route('lead.wip-status', ['lead' => $lead->id]), [
            'wip_status' => 'SIP Booked',
            'sip_booked_at' => $browserIso,
        ])->assertOk()
            ->assertJsonPath('wip_status', 'SIP Booked')
            ->assertJsonPath('sip.sip_booked_at', $when->toIso8601String());

        $lead->refresh();
        $this->assertSame('SIP Booked', $lead->wip_status);
        $this->assertTrue($lead->sip_booked_at->equalTo($when));
        $this->assertNull($lead->sip_prep_completed_at);

        $this->actingAs($user)->postJson(route('lead.sip-prep-complete', ['lead' => $lead->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($lead->fresh()->sip_prep_completed_at);
    }

    public function test_leaving_sip_booked_clears_appointment_and_prep_state(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead('SIP Leaving');
        $lead->update([
            'wip_status' => 'SIP Booked',
            'sip_booked_at' => now()->addHour(),
            'sip_prep_completed_at' => now(),
        ]);

        $this->actingAs($user)->patchJson(route('lead.wip-status', ['lead' => $lead->id]), [
            'wip_status' => 'Ready to Refer',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('Ready to Refer', $lead->wip_status);
        $this->assertNull($lead->sip_booked_at);
        $this->assertNull($lead->sip_prep_completed_at);
    }

    private function lead(string $name): Lead
    {
        static $sequence = 7600000;
        $sequence++;

        return Lead::query()->create([
            'vicidial_lead_id' => 'test-'.$sequence,
            'phone_number' => '07700'.substr((string) $sequence, -6),
            'first_name' => $name,
            'last_name' => 'Case',
            'wip_status' => 'Collecting Docs',
        ]);
    }
}
