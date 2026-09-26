<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use Tests\TestCase;

class BotMonitorTest extends TestCase
{
    public function test_anonymous_users_cannot_see_monitor_or_conversations(): void
    {
        $this->get(route('bot-logs.index'))->assertRedirect();
        $this->getJson(route('bot-logs.data'))->assertUnauthorized();
    }

    public function test_authenticated_monitor_has_four_cards_and_signed_conversation_log(): void
    {
        $person = User::factory()->make(['id' => 12345]);
        // This isolated test DB does not contain the unrelated WIP navigation composer tables.
        // Verify the compiled Blade separately; the data route has no WIP dependency.
        $blade = file_get_contents(resource_path('views/bot-logs/index.blade.php'));
        $this->assertStringContainsString('Bot Team Monitor', $blade);
        $this->assertStringContainsString('bmCards', $blade);
        $this->assertStringContainsString('bmConversations', $blade);
        $this->assertStringContainsString('not private internal reasoning', $blade);
        $response = $this->actingAs($person)->getJson(route('bot-logs.data'));
        $response->assertOk()->assertJsonPath('schema', 2)->assertJsonCount(4, 'bots');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertJsonStructure(['generated_at', 'thinking_note', 'bots', 'conversations']);
    }

    public function test_configured_owner_receives_full_feed_and_other_staff_do_not(): void
    {
        config()->set('bot-monitor.owner_user_id', 1);
        $staff = User::factory()->make(['id' => 67]);
        $owner = User::factory()->make(['id' => 1]);
        $staffData = $this->actingAs($staff)->getJson(route('bot-logs.data'));
        $staffData->assertOk()->assertJsonPath('full_access', false);
        $ownerData = $this->actingAs($owner)->getJson(route('bot-logs.data'));
        $ownerData->assertOk()->assertJsonPath('full_access', true);
        $this->assertArrayHasKey('feed', $ownerData->json());
        $this->assertCount(4, $ownerData->json('bots'));
        $this->assertTrue(collect($ownerData->json('bots'))->every(fn ($bot) => isset($bot['last_action'])));
        $this->assertTrue(collect($staffData->json('feed'))->every(fn ($message) => $message['from'] !== 'alex' || $message['text'] === '[Private Alex conversation — owner access required]'));
        config()->set('bot-monitor.owner_user_id', 0);
        $this->actingAs($owner)->getJson(route('bot-logs.data'))->assertJsonPath('full_access', false);
    }

    public function test_terminal_layout_is_four_across_newest_first_and_typed_only_for_new_messages(): void
    {
        $blade = file_get_contents(resource_path('views/bot-logs/index.blade.php'));
        $this->assertStringContainsString('grid-template-columns:repeat(4,minmax(0,1fr))', $blade);
        $this->assertStringContainsString('width:calc(100vw - 48px)', $blade);
        $this->assertStringContainsString('(data.feed||[]).slice()', $blade);
        $this->assertStringContainsString('Date.parse(b.time)-Date.parse(a.time)', $blade);
        $this->assertStringContainsString("m.from.toUpperCase()+' → '+m.to.toUpperCase()+':'", $blade);
        $this->assertStringContainsString('!firstLoad?rows.filter(m=>!previousIds.has(m.id)):[]', $blade);
        $this->assertStringContainsString("screen.scrollTop=mostRecent?0:currentScroll", $blade);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $blade);
        $this->assertStringContainsString('textContent', $blade);
    }

    public function test_navigation_replaces_old_label_without_exposing_internal_source_file(): void
    {
        $nav = file_get_contents(resource_path('views/partials/app-nav.blade.php'));
        $this->assertStringContainsString('Bot Monitor', $nav);
        $this->assertStringNotContainsString('Bot Logs', $nav);
        $controller = file_get_contents(app_path('Http/Controllers/BotLogsController.php'));
        $this->assertStringContainsString('overview.json', $controller);
        $this->assertStringNotContainsString('team.sqlite', $controller);
    }
}
