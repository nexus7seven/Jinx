<?php

namespace Tests\Unit;

use App\Services\CreditReports\Parsers\PdfCreditReportParser;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PdfCreditReportParserTest extends TestCase
{
    public function test_parse_text_extracts_sanitized_transunion_fixture_values(): void
    {
        $parser = new PdfCreditReportParser(new Parser());
        $fixturePath = base_path('tests/Fixtures/sanitized_transunion_credit_report_extracted.txt');
        $sampleText = file_get_contents($fixturePath);
        $this->assertNotFalse($sampleText);

        $parsed = $parser->parseText((string) $sampleText);

        $this->assertNotEmpty($parsed['debts']);
        $this->assertCount(2, $parsed['county_court_judgments']);

        $creditors = array_values(array_filter(array_map(
            static fn (array $debt): string => (string) ($debt['creditor'] ?? ''),
            $parsed['debts']
        )));

        $this->assertContains('Bits Credit Card', $creditors);
        $this->assertContains('Fair Finance', $creditors);
        $this->assertContains('Tesco Mobile Handset', $creditors);
        $this->assertContains('Scottishpower Energy Retail Limited', $creditors);
        $this->assertContains('Ee Limited', $creditors);

        $caseNumbers = array_map(
            static fn (array $ccj): string => (string) ($ccj['case_number'] ?? ''),
            $parsed['county_court_judgments']
        );
        $amounts = array_map(
            static fn (array $ccj): float => (float) ($ccj['amount'] ?? 0),
            $parsed['county_court_judgments']
        );

        $this->assertContains('SAMP788Q', $caseNumbers);
        $this->assertContains('SAMP658M', $caseNumbers);
        $this->assertContains(1009.0, $amounts);
        $this->assertContains(195.0, $amounts);

        $this->assertArrayHasKey('creditor', $parsed['debts'][0]);
        $this->assertArrayHasKey('balance', $parsed['debts'][0]);
        $this->assertArrayHasKey('status', $parsed['debts'][0]);
        $this->assertArrayHasKey('case_number', $parsed['county_court_judgments'][0]);
        $this->assertArrayHasKey('amount', $parsed['county_court_judgments'][0]);
        $this->assertArrayHasKey('status', $parsed['county_court_judgments'][0]);
    }

    public function test_parse_text_returns_empty_collections_for_empty_pdf_text(): void
    {
        $parser = new PdfCreditReportParser(new Parser());

        $parsed = $parser->parseText(" \n\t ");

        $this->assertSame('', $parsed['text']);
        $this->assertSame([], $parsed['debts']);
        $this->assertSame([], $parsed['county_court_judgments']);
    }
}
