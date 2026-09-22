<?php

namespace Tests\Feature\Wip;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WipAllLeadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_is_a_plain_list_including_every_case_status(): void
    {
        $user = User::factory()->create();
        $this->lead('Alice', 'Dead', 'Archived');
        $this->lead('Bob', 'IVA Verified', 'Partner');
        $this->lead('Cara', 'New Lead', 'Web');

        $response = $this->actingAs($user)->get('/wip?show=all');
        $response->assertOk()
            ->assertSee('All leads')
            ->assertSee('Alice')
            ->assertSee('Bob')
            ->assertSee('Cara')
            ->assertDontSee('id="wip-dashboard-grid"', false)
            ->assertDontSee('Priority', false)
            ->assertSee('3 Jinx leads');

        $this->assertDatabaseCount('wip_case_queue_items', 0);
    }

    public function test_search_is_global_and_matches_full_name_phone_email_and_lead_id(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead('Jane', 'Dead', 'Zebra', 'Smith', '07700123456', 'jane@example.test');
        $other = $this->lead('Alan', 'Collecting Docs', 'Other');
        foreach (['Jane Smith', '07700123456', 'jane@example.test', (string) $lead->id, (string) $lead->vicidial_lead_id] as $term) {
            $this->actingAs($user)->get('/wip?show=all&q='.urlencode($term))
                ->assertOk()
                ->assertSee('Jane Smith')
                ->assertDontSee('Alan')
                ->assertSee('1 Jinx leads');
        }

        $this->actingAs($user)->get('/wip?show=all&q=NotAnActualLead')
            ->assertOk()->assertSee('No leads match your search.');
    }

    public function test_status_and_source_filters_apply_to_the_full_directory(): void
    {
        $user = User::factory()->create();
        $this->lead('One', 'Dead', 'Zebra');
        $this->lead('Two', 'Dead', 'Website');
        $this->lead('Three', 'New Lead', 'Zebra');

        $this->actingAs($user)->get('/wip?show=all&status=Dead&source=Zebra')
            ->assertOk()->assertSee('One')->assertDontSee('Two')->assertDontSee('Three')
            ->assertSee('1 Jinx leads');
    }

    public function test_all_list_is_paginated_without_losing_global_search(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 55; $i++) {
            $this->lead('DirectoryPerson'.$i, 'Dead', 'Website');
        }

        $this->actingAs($user)->get('/wip?show=all')->assertOk()
            ->assertSee('Showing 1–50 of 55 Jinx leads')
            ->assertSee('Next →')
            ->assertSee('Page 1 of 2');

        $this->actingAs($user)->get('/wip?show=all&page=2')->assertOk()
            ->assertSee('Showing 51–55 of 55 Jinx leads')
            ->assertSee('Page 2 of 2');
    }
    private function lead(
        string $first,
        string $status,
        string $source,
        string $last = 'Case',
        string $phone = '',
        string $email = ''
    ): Lead {
        static $sequence = 0;
        $sequence++;

        return Lead::query()->create([
            'vicidial_lead_id' => 'directory-'.$sequence,
            'phone_number' => $phone !== '' ? $phone : '07700'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email !== '' ? $email : null,
            'wip_status' => $status,
            'source' => $source,
        ]);
    }
}
