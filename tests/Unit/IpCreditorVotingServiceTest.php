<?php

namespace Tests\Unit;

use App\Services\IpCreditorVotingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IpCreditorVotingServiceTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function test_workbook_statuses_resolve_to_only_the_three_hard_buckets(string $status, string $expected): void
    {
        $service = new IpCreditorVotingService();

        $this->assertSame($expected, $service->interpretStatus($status));
    }

    public static function statusProvider(): array
    {
        return [
            ['Accept', 'accept'],
            ['ACCEPT', 'accept'],
            ['Accept with conditions', 'accept'],
            ['Accept with modifications', 'accept'],
            ['Referral', 'accept'],
            ['Trial @ MOC', 'accept'],
            ['Represented by TIX', 'accept'],
            ['Represented', 'accept'],
            ['Accept - via house vote', 'accept'],
            ['Accept - with conditions', 'accept'],
            ['Accept - Trial @ MOC', 'accept'],
            ['Accept - Referral', 'accept'],
            ['Reject', 'reject'],
            ['REJECT', 'reject'],
            ['Non-voting', 'non_voting'],
            ['Non-vote', 'non_voting'],
            ['DO NOT VOTE', 'non_voting'],
            ['NO VOTE', 'non_voting'],
            ['MOC', 'accept'],
            ['Will Consider', 'unknown'],
            ['Trial @ MOC/Reject', 'unknown'],
            ['Non-voting or reject', 'unknown'],
            ['Dividend requirement', 'unknown'],
        ];
    }

    public function test_accept_subtypes_are_display_only_and_remain_hard_accepts(): void
    {
        $service = new IpCreditorVotingService();

        $this->assertSame('via_house', $service->acceptKind('Represented by TIX', 'TIX'));
        $this->assertSame('conditions', $service->acceptKind('Accept with conditions'));
        $this->assertSame('trial_moc', $service->acceptKind('Trial @ MOC'));
        $this->assertSame('trial_moc', $service->acceptKind('MOC'));
        $this->assertSame('referral', $service->acceptKind('Referral'));

        $this->assertSame('ACCEPT · VIA HOUSE', $service->displayLabel('accept', 'Represented by TIX', 'TIX'));
        $this->assertSame('ACCEPT · WITH CONDITIONS', $service->displayLabel('accept', 'Accept with conditions'));
        $this->assertSame('ACCEPT · TRIAL @ MOC', $service->displayLabel('accept', 'Trial @ MOC'));
        $this->assertSame('ACCEPT · REFERRAL', $service->displayLabel('accept', 'Referral'));
    }

    public function test_summary_regression_for_reported_tig_case_counts_all_accept_variants_as_accept(): void
    {
        $service = new IpCreditorVotingService();

        $summary = $service->summarise([
            ['balance' => 13713.00, 'outcome' => 'accept'],
            ['balance' => 554.00, 'outcome' => 'reject'],
            ['balance' => 1914.00, 'outcome' => 'non_voting'],
        ]);

        $this->assertSame(16181.0, $summary['total_debt']);
        $this->assertSame(14267.0, $summary['voting_balance']);
        $this->assertSame(13713.0, $summary['accept_balance']);
        $this->assertSame(554.0, $summary['reject_balance']);
        $this->assertSame(1914.0, $summary['non_vote_balance']);
        $this->assertSame(96.1, $summary['accept_percent']);
        $this->assertSame(3.9, $summary['reject_percent']);
        $this->assertSame(100.0, $summary['accept_percent'] + $summary['reject_percent']);
    }
}
