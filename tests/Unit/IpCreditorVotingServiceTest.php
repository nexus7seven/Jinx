<?php

namespace Tests\Unit;

use App\Services\IpCreditorVotingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IpCreditorVotingServiceTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function test_workbook_statuses_are_interpreted_without_erasing_raw_meaning(string $status, string $expected): void
    {
        $service = new IpCreditorVotingService();

        $this->assertSame($expected, $service->interpretStatus($status));
    }

    public static function statusProvider(): array
    {
        return [
            ['Accept', 'accept'],
            ['ACCEPT', 'accept'],
            ['Accept with conditions', 'accept_conditional'],
            ['Accept with modifications', 'accept_conditional'],
            ['Referral', 'accept_conditional'],
            ['Trial @ MOC', 'accept_conditional'],
            ['MOC', 'unknown'],
            ['Will Consider', 'unknown'],
            ['Reject', 'reject'],
            ['REJECT', 'reject'],
            ['Non-voting', 'non_voting'],
            ['DO NOT VOTE', 'non_voting'],
            ['NO VOTE', 'non_voting'],
            ['Represented by TIX', 'represented'],
            ['Represented', 'represented'],
            ['Trial @ MOC/Reject', 'unknown'],
            ['Non-voting or reject', 'unknown'],
            ['Dividend requirement', 'unknown'],
        ];
    }
}
