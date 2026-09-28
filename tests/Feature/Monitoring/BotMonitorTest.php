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
        $this->assertStringContainsString('BOT CONTROL TERMINAL', $blade);
        $this->assertStringContainsString( 'id="cards"' , $blade);
        $this->assertStringContainsString( 'id="feed"' , $blade);
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

    public function test_contact_rail_filters_both_directions_and_source_work_with_newest_first_typing(): void
    {
        $blade = file_get_contents(resource_path('views/bot-logs/index.blade.php'));
        $this->assertStringContainsString('grid-template-columns:205px minmax(0,1fr)', $blade);
        $this->assertStringContainsString("@extends('layouts.monitor')", $blade);
        $this->assertStringContainsString('(snapshot?.feed||[]).slice()', $blade);
        $this->assertStringContainsString('Date.parse(b.time)-Date.parse(a.time)', $blade);
        $this->assertStringContainsString("m.from.toUpperCase()+' → '+m.to.toUpperCase()+':'", $blade);
        $this->assertStringContainsString('initial?[]:rows.filter(m=>!seen.has(m.id))', $blade);
        $this->assertStringContainsString("feed.scrollTop=added.length?0:scroll", $blade);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $blade);
        $this->assertStringContainsString('textContent', $blade);
        $this->assertStringNotContainsString('LAST ACTION</', $blade);
        $this->assertStringContainsString('id="clearFilter"', $blade);
        $this->assertStringContainsString('contactBreathe', $blade);
        $this->assertStringContainsString('line.thought', $blade);
        $this->assertStringContainsString('line:nth-child(4n+2)', $blade);
        $this->assertStringContainsString('control-layout', $blade);
        $this->assertStringContainsString("m.kind==='thought'", $blade);
        $this->assertStringContainsString('m.from===role||m.to===role', $blade);
        $this->assertStringNotContainsString("el('asOf')", $blade);
        $this->assertStringContainsString('SYSTEM EVENTS', $blade);
        $this->assertStringContainsString('function typeLine(', $blade);
        $this->assertStringContainsString('Math.ceil(text.length/220)', $blade);
        $this->assertStringContainsString('},38)', $blade);
        $this->assertStringContainsString('function updateDecor', str_replace('const updateDecor', 'function updateDecor', $blade));
        $this->assertStringContainsString('message{font-size:17px', $blade);
        $this->assertStringContainsString('function typeLine(', $blade);
        $this->assertStringContainsString('setInterval(poll,1500)', $blade);
        $this->assertStringContainsString('WORK QUEUE', $blade);
        $this->assertStringContainsString('id="queue"', $blade);
        $this->assertStringContainsString('id="viewQueue"', $blade);
        $this->assertStringContainsString('id="cards"', $blade);
        $this->assertStringContainsString('replyDrafts=new Map()', $blade);
        $this->assertStringContainsString('function queueCardKey(card)', $blade);
        $this->assertStringContainsString('function captureQueueDrafts()', $blade);
        $this->assertStringContainsString('function restoreReplyDraft(input)', $blade);
        $this->assertStringContainsString('function queueInputBusy(input)', $blade);
        $this->assertStringContainsString('function focusedQueueField()', $blade);
        $this->assertStringContainsString('function patchQueueInPlace(rows)', $blade);
        $this->assertStringContainsString('if(editing){patchQueueInPlace(rows);return;}', $blade);
        $this->assertStringContainsString('clearReplyDraft(key)', $blade);
        $this->assertStringContainsString('input[data-card-key]', $blade);
        $this->assertStringContainsString('setSelectionRange', $blade);
        $this->assertStringNotContainsString('fragment.append(prior)', $blade);
        $this->assertStringNotContainsString("@extends('layouts.app')", $blade);
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
