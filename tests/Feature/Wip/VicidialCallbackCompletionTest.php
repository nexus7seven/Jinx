<?php

namespace Tests\Feature\Wip;

use App\Models\Lead;
use App\Services\VicidialCallbackService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VicidialCallbackCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.vicidial.db_connection', 'asterisk');
        Config::set('database.connections.asterisk', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::purge('asterisk');

        Schema::connection('asterisk')->create('vicidial_list', function (Blueprint $table): void {
            $table->integer('lead_id')->primary();
            $table->string('status');
        });
        Schema::connection('asterisk')->create('vicidial_callbacks', function (Blueprint $table): void {
            $table->increments('callback_id');
            $table->integer('lead_id');
            $table->string('status');
            $table->dateTime('entry_time');
            $table->dateTime('callback_time');
            $table->string('comments')->nullable();
        });
        Schema::connection('asterisk')->create('vicidial_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('lead_id');
            $table->dateTime('call_date');
            $table->string('status')->nullable();
        });
    }

    private function addCallback(int $vicidialId, string $entry, string $due): Lead
    {
        $lead = Lead::query()->create([
            'vicidial_lead_id' => $vicidialId,
            'first_name' => 'Callback',
            'last_name' => 'Test',
            'phone_number' => '07700'.str_pad((string) $vicidialId, 6, '0', STR_PAD_LEFT),
            'wip_status' => 'Callback Set',
        ]);
        DB::connection('asterisk')->table('vicidial_list')->insert(['lead_id' => $vicidialId, 'status' => 'CBHOLD']);
        DB::connection('asterisk')->table('vicidial_callbacks')->insert([
            'lead_id' => $vicidialId, 'status' => 'LIVE',
            'entry_time' => $entry, 'callback_time' => $due,
        ]);
        return $lead;
    }

    private function dial(int $vicidialId, string $at, string $disposition = 'WIP'): void
    {
        DB::connection('asterisk')->table('vicidial_log')->insert([
            'lead_id' => $vicidialId, 'call_date' => $at, 'status' => $disposition,
        ]);
    }

    public function test_live_callback_disappears_after_the_scheduled_dial_even_if_vicidial_keeps_live_status(): void
    {
        $lead = $this->addCallback(1001, '2026-09-22 12:29:38', '2026-09-22 13:00:00');
        $this->assertCount(1, app(VicidialCallbackService::class)->activeForJinxLeads());

        $this->dial(1001, '2026-09-22 13:00:15');
        $this->assertSame([], app(VicidialCallbackService::class)->activeForJinxLeads());
        $this->assertDatabaseHas('vicidial_callbacks', ['lead_id' => 1001, 'status' => 'LIVE'], 'asterisk');
        $this->assertSame('Callback Set', $lead->fresh()->wip_status);
    }
    public function test_callback_dialled_early_within_thirty_minutes_is_completed(): void
    {
        $this->addCallback(1002, '2026-09-22 12:59:14', '2026-09-22 14:00:00');
        $this->dial(1002, '2026-09-22 13:53:26');
        $this->assertSame([], app(VicidialCallbackService::class)->activeForJinxLeads());
    }

    public function test_unrelated_earlier_call_does_not_clear_a_future_callback(): void
    {
        $this->addCallback(1003, '2026-09-22 12:59:14', '2026-09-22 14:00:00');
        $this->dial(1003, '2026-09-22 13:15:00');
        $this->assertCount(1, app(VicidialCallbackService::class)->activeForJinxLeads());
    }

    public function test_old_call_cannot_clear_a_newly_booked_callback(): void
    {
        $this->addCallback(1004, '2026-09-22 13:50:00', '2026-09-22 14:00:00');
        $this->dial(1004, '2026-09-22 13:45:00');
        $this->assertCount(1, app(VicidialCallbackService::class)->activeForJinxLeads());
    }

    public function test_another_leads_call_never_clears_this_callback(): void
    {
        $this->addCallback(1005, '2026-09-22 12:30:00', '2026-09-22 14:00:00');
        $this->addCallback(1006, '2026-09-22 12:30:00', '2026-09-22 14:00:00');
        $this->dial(1005, '2026-09-22 14:00:05');
        $remaining = app(VicidialCallbackService::class)->activeForJinxLeads();
        $this->assertCount(1, $remaining);
        $this->assertSame(1006, $remaining[0]['vicidial_lead_id']);
    }
}
