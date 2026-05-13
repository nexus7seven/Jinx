<?php

namespace Tests\Unit\Services;

use App\Services\VicidialRemarketingHoldService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VicidialRemarketingHoldServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.vicidial.db_connection', 'asterisk');
        Config::set('database.connections.asterisk', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::connection('asterisk')->create('vicidial_list', function (Blueprint $table): void {
            $table->integer('lead_id')->primary();
            $table->string('status');
            $table->string('list_id')->nullable();
        });
    }

    public function test_it_preserves_callback_status_while_moving_to_holding_list(): void
    {
        DB::connection('asterisk')->table('vicidial_list')->insert([
            'lead_id' => 1001,
            'status' => 'CBHOLD',
            'list_id' => '999',
        ]);

        Log::spy();

        $service = app(VicidialRemarketingHoldService::class);
        $result = $service->parkLeadInHoldingListPreservingCallbacks(1001, '5555555555');

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['updated']);
        $this->assertSame(1, $result['affected_rows']);
        $this->assertTrue($result['preserved_callback_status']);
        $this->assertDatabaseHas('vicidial_list', [
            'lead_id' => 1001,
            'status' => 'CBHOLD',
            'list_id' => '5555555555',
        ], 'asterisk');

        Log::shouldHaveReceived('info')->with('preserved_callback_status', \Mockery::on(function (array $context): bool {
            return $context['lead_id'] === 1001
                && $context['old_status'] === 'CBHOLD'
                && $context['list_id_before'] === '999'
                && $context['list_id_after'] === '5555555555';
        }));
    }



    public function test_it_marks_already_parked_callback_row_as_handled_even_when_affected_rows_is_zero(): void
    {
        DB::connection('asterisk')->table('vicidial_list')->insert([
            'lead_id' => 1003,
            'status' => 'CALLBK',
            'list_id' => '5555555555',
        ]);

        $service = app(VicidialRemarketingHoldService::class);
        $result = $service->parkLeadInHoldingListPreservingCallbacks(1003, '5555555555');

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['updated']);
        $this->assertSame(0, $result['affected_rows']);
        $this->assertTrue($result['preserved_callback_status']);
        $this->assertDatabaseHas('vicidial_list', [
            'lead_id' => 1003,
            'status' => 'CALLBK',
            'list_id' => '5555555555',
        ], 'asterisk');
    }

    public function test_it_sets_non_callback_status_to_hold_when_moving_to_holding_list(): void
    {
        DB::connection('asterisk')->table('vicidial_list')->insert([
            'lead_id' => 1002,
            'status' => 'CBNA',
            'list_id' => '111',
        ]);

        $service = app(VicidialRemarketingHoldService::class);
        $result = $service->parkLeadInHoldingListPreservingCallbacks(1002, '5555555555');

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['updated']);
        $this->assertSame(1, $result['affected_rows']);
        $this->assertFalse($result['preserved_callback_status']);
        $this->assertDatabaseHas('vicidial_list', [
            'lead_id' => 1002,
            'status' => 'HOLD',
            'list_id' => '5555555555',
        ], 'asterisk');
    }
}
