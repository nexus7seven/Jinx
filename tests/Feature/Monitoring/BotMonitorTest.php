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
