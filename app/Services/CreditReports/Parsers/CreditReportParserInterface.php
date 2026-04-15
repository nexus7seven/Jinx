<?php

namespace App\Services\CreditReports\Parsers;

interface CreditReportParserInterface
{
    /**
     * @return array{
     *   text:string,
     *   debts:array<int, array<string, mixed>>,
     *   county_court_judgments:array<int, array<string, mixed>>
     * }
     */
    public function parse(string $absolutePath): array;
}
