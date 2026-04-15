<?php

namespace Tests\Support;

use App\Services\CreditReports\CreditReportParserFactory;
use App\Services\CreditReports\Parsers\CreditReportParserInterface;

/**
 * Test double: returns a fixed parse result regardless of file path (for feature tests without binary PDF fixtures).
 */
class FixedPayloadCreditReportParserFactory extends CreditReportParserFactory
{
    /**
     * @param array{
     *   text:string,
     *   debts:array<int, array<string, mixed>>,
     *   county_court_judgments:array<int, array<string, mixed>>
     * } $payload
     */
    public function __construct(private readonly array $payload) {}

    public function make(string $type): CreditReportParserInterface
    {
        return new class($this->payload) implements CreditReportParserInterface
        {
            /**
             * @param array{
             *   text:string,
             *   debts:array<int, array<string, mixed>>,
             *   county_court_judgments:array<int, array<string, mixed>>
             * } $data
             */
            public function __construct(private readonly array $data) {}

            public function parse(string $absolutePath): array
            {
                return $this->data;
            }
        };
    }
}
