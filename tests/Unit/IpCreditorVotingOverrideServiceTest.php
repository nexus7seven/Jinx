<?php

namespace Tests\Unit;

use App\Services\IpCreditorVotingOverrideService;
use App\Services\IpCreditorVotingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IpCreditorVotingOverrideServiceTest extends TestCase
{
    private IpCreditorVotingOverrideService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ip_creditor_voting_override_audits');
        Schema::dropIfExists('ip_creditor_voting_overrides');
        Schema::dropIfExists('decision_creditor_source_rows');
        Schema::dropIfExists('creditor_aliases');
        Schema::dropIfExists('creditors');

        Schema::create('creditors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('creditor_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creditor_id');
            $table->string('alias');
        });

        Schema::create('decision_creditor_source_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creditor_id')->nullable();
            $table->string('partner_key')->nullable();
            $table->string('status_text')->nullable();
            $table->text('detail_text')->nullable();
            $table->string('representative_key')->nullable();
            $table->string('source_name')->nullable();
            $table->string('sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
        });

        Schema::create('ip_creditor_voting_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creditor_id');
            $table->string('ip_key');
            $table->string('status_text');
            $table->string('outcome');
            $table->string('voting_house')->nullable();
            $table->text('condition_text')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_creditor_voting_override_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creditor_id');
            $table->string('ip_key');
            $table->string('action');
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('source');
            $table->text('source_message')->nullable();
            $table->unsignedBigInteger('assistant_conversation_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->service = new IpCreditorVotingOverrideService(new IpCreditorVotingService());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ip_creditor_voting_override_audits');
        Schema::dropIfExists('ip_creditor_voting_overrides');
        Schema::dropIfExists('decision_creditor_source_rows');
        Schema::dropIfExists('creditor_aliases');
        Schema::dropIfExists('creditors');

        parent::tearDown();
    }

    public function test_natural_ip_and_voting_labels_are_normalised(): void
    {
        $this->assertSame('lawson_fox', $this->service->normaliseIpKey('Lawson Fox'));
        $this->assertSame('anchorage_chambers', $this->service->normaliseIpKey('AC'));
        $this->assertSame('Accept - with conditions', $this->service->normaliseStatusText('accept with conditions'));
        $this->assertSame('Accept - Trial @ MOC', $this->service->normaliseStatusText('trial at moc'));
        $this->assertSame('Accept - Referral', $this->service->normaliseStatusText('referral'));
        $this->assertSame('Reject', $this->service->normaliseStatusText('reject'));
        $this->assertSame('Non-vote', $this->service->normaliseStatusText('do not vote'));
    }

    public function test_assistant_change_overrides_workbook_and_revert_restores_it_with_audit_history(): void
    {
        $creditorId = DB::table('creditors')->insertGetId(['name' => 'Bamboo Loans']);

        DB::table('creditor_aliases')->insert([
            'creditor_id' => $creditorId,
            'alias' => 'Bamboo',
        ]);

        DB::table('decision_creditor_source_rows')->insert([
            'creditor_id' => $creditorId,
            'partner_key' => 'lawson_fox',
            'status_text' => 'Accept',
            'detail_text' => null,
            'representative_key' => null,
            'source_name' => 'Lawson Fox Criteria.xlsx',
            'sheet' => 'All Creditors',
            'source_row' => 42,
        ]);

        $prepared = $this->service->prepare([
            'operation' => 'set',
            'ip' => 'Lawson Fox',
            'creditor' => 'Bamboo',
            'voting' => 'Reject',
        ], 'Lawson Fox now reject all Bamboo loans');

        $this->assertSame('ready', $prepared['status']);
        $this->assertStringContainsString('workbook rule Accept', $prepared['message']);
        $this->assertStringContainsString('Change it to Reject', $prepared['message']);

        $changed = $this->service->executePending($prepared['pending'], null, null, null);

        $this->assertStringContainsString('Updated Lawson Fox / Bamboo Loans to Reject', $changed['message']);
        $this->assertDatabaseHas('ip_creditor_voting_overrides', [
            'creditor_id' => $creditorId,
            'ip_key' => 'lawson_fox',
            'status_text' => 'Reject',
            'outcome' => 'reject',
        ]);
        $this->assertSame(1, DB::table('ip_creditor_voting_override_audits')->count());

        $revert = $this->service->prepare([
            'operation' => 'revert',
            'ip' => 'Lawson Fox',
            'creditor' => 'Bamboo Loans',
        ], 'Revert Lawson Fox Bamboo back to the workbook');

        $this->assertSame('ready', $revert['status']);
        $this->assertStringContainsString('return all Lawson Fox cases to the workbook rule (Accept)', $revert['message']);

        $reverted = $this->service->executePending($revert['pending'], null, null, null);

        $this->assertStringContainsString('Reverted Lawson Fox / Bamboo Loans to the workbook rule: Accept', $reverted['message']);
        $this->assertDatabaseMissing('ip_creditor_voting_overrides', [
            'creditor_id' => $creditorId,
            'ip_key' => 'lawson_fox',
        ]);
        $this->assertSame(2, DB::table('ip_creditor_voting_override_audits')->count());
        $this->assertDatabaseHas('decision_creditor_source_rows', [
            'creditor_id' => $creditorId,
            'partner_key' => 'lawson_fox',
            'status_text' => 'Accept',
        ]);
    }
}
