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

        $this->assertStringContainsString('class="wip-stack-handle"', $index);
        $this->assertStringContainsString('id="wip-actioned-modal"', $index);
        $this->assertStringContainsString('Actioned ↓', $index);
        $this->assertStringContainsString('Move to bottom', $index);
        $this->assertStringContainsString('id="wip-actioned-waiting-on"', $index);
        $this->assertStringNotContainsString('id="wip-actioned-chase-preset"', $index);
        $this->assertStringNotContainsString('id="wip-actioned-note"', $index);
        $this->assertStringNotContainsString('scrollIntoView', $index);
        $this->assertStringContainsString('class="wip-board-layout"', $index);
        $this->assertStringContainsString('grid-template-columns:minmax(0,4fr) minmax(250px,1fr)', $index);
        $this->assertStringContainsString('class="wip-callback-item', $index);

        $this->assertStringContainsString('id="wipAssistantLauncher"', $assistant);
        $this->assertStringContainsString('.wip-assistant.is-open', $assistant);
        $this->assertStringNotContainsString('backdrop-filter', $assistant);
        $this->assertStringNotContainsString('wip-assistant-scrim', $assistant);
    }

    private function lead(string $name): Lead
    {
        static $sequence = 7600000;
        $sequence++;

        return Lead::query()->create([
            'vicidial_lead_id' => $sequence,
            'phone_number' => '07700'.substr((string) $sequence, -6),
            'first_name' => $name,
            'last_name' => 'Case',
            'wip_status' => 'Collecting Docs',
        ]);
    }
}
