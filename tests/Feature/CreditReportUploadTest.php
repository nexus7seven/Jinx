<?php

namespace Tests\Feature;

use App\Models\CreditReport;
use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\User;
use App\Services\CreditReports\CreditReportFileTypeDetector;
use App\Services\CreditReports\CreditReportParserFactory;
use App\Services\CreditReports\Parsers\PdfCreditReportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Tests\Support\FixedPayloadCreditReportParserFactory;
use Tests\TestCase;

class CreditReportUploadTest extends TestCase
{
    use RefreshDatabase;

    private function sanitizedFixtureText(): string
    {
        $path = base_path('tests/Fixtures/sanitized_transunion_credit_report_extracted.txt');
        $text = file_get_contents($path);
        $this->assertNotFalse($text);

        return (string) $text;
    }

    /**
     * Creditors required so sanitized fixture rows match and import; CCJ uses dedicated creditor row.
     */
    private function seedCreditorsForSanitizedSample(): void
    {
        $defaults = [
            'voting_house' => 'WATCH',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ];

        foreach ([
            'Could Not Match',
            'County Court Judgment',
            'Bits Credit Card',
            'Bits Credit Builder - Fea Card LTD',
            'Fair Finance',
            'Tesco Mobile Handset',
            'Utilita Energy LTD',
            'Scottishpower Energy Retail Limited',
            'Tesco Mobile Telecoms',
            'Ee Limited',
            'Lowell',
            'Zilch Technology LTD',
            'Monese Instant Account',
            'Starling Bank',
            'Lloyds Bank',
        ] as $name) {
            Creditor::firstOrCreate(['name' => $name], $defaults);
        }
    }

    /**
     * End-to-end HTTP + storage + import using fixed parser output from the sanitized text fixture (no personal PDF in repo).
     */
    public function test_upload_pdf_route_imports_debts_and_ccjs_from_sanitized_fixture(): void
    {
        $log = Log::spy();

        $fixtureText = $this->sanitizedFixtureText();
        $parsed = (new PdfCreditReportParser(new Parser()))->parseText($fixtureText);
        $this->app->instance(
            CreditReportParserFactory::class,
            new FixedPayloadCreditReportParserFactory($parsed)
        );

        $this->seedCreditorsForSanitizedSample();

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'credit-report-upload-'.uniqid(),
        ]);

        $file = UploadedFile::fake()->create('sanitized_report.pdf', 12, 'application/pdf');

        $response = $this->actingAs($user)->post('/lead/'.$lead->id.'/credit-reports', [
            'report_files' => [$file],
        ]);

        $response->assertRedirect('/lead/'.$lead->id.'#credit-report-upload');
        $response->assertSessionHas('credit_report_success');
        $response->assertSessionMissing('credit_report_error');

        $creditReport = CreditReport::where('lead_id', $lead->id)->first();
        $this->assertNotNull($creditReport);
        $this->assertSame('processed', $creditReport->status);

        $log->shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Credit report parser selected'
                    && ($context['type'] ?? null) === CreditReportFileTypeDetector::TYPE_PDF;
            });

        $importedNames = Debt::query()
            ->where('lead_id', $lead->id)
            ->where('source_expected', 'credit_check')
            ->with('creditor')
            ->get()
            ->map(fn (Debt $d) => $d->creditor->name)
            ->all();

        $this->assertContains('Bits Credit Card', $importedNames);
        $this->assertContains('Bits Credit Builder - Fea Card LTD', $importedNames);
        $this->assertContains('Fair Finance', $importedNames);
        $this->assertContains('Tesco Mobile Handset', $importedNames);
        $this->assertContains('Scottishpower Energy Retail Limited', $importedNames);
        $this->assertContains('Tesco Mobile Telecoms', $importedNames);
        $this->assertContains('Ee Limited', $importedNames);

        $ccjCreditor = Creditor::where('name', 'County Court Judgment')->first();
        $this->assertNotNull($ccjCreditor);

        $ccjDebts = Debt::query()
            ->where('lead_id', $lead->id)
            ->where('creditor_id', $ccjCreditor->id)
            ->get();

        $refs = $ccjDebts->pluck('reference')->all();
        $balances = $ccjDebts->pluck('balance')->map(fn ($b) => (float) $b)->all();

        $this->assertContains('SAMP788Q', $refs);
        $this->assertContains('SAMP658M', $refs);
        $this->assertContains(1009.0, $balances);
        $this->assertContains(195.0, $balances);
    }

    public function test_upload_pdf_unusable_parse_fails_without_imports(): void
    {
        $emptyPayload = [
            'text' => '',
            'debts' => [],
            'county_court_judgments' => [],
        ];
        $this->app->instance(
            CreditReportParserFactory::class,
            new FixedPayloadCreditReportParserFactory($emptyPayload)
        );

        $this->seedCreditorsForSanitizedSample();

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'credit-report-empty-'.uniqid(),
        ]);

        $file = UploadedFile::fake()->create('emptyish.pdf', 8, 'application/pdf');

        $response = $this->actingAs($user)->post('/lead/'.$lead->id.'/credit-reports', [
            'report_files' => [$file],
        ]);

        $response->assertRedirect('/lead/'.$lead->id.'#credit-report-upload');
        $response->assertSessionHas('credit_report_error');
        $response->assertSessionMissing('credit_report_success');

        $this->assertDatabaseHas('credit_reports', [
            'lead_id' => $lead->id,
            'status' => 'failed',
        ]);

        $this->assertSame(0, Debt::where('lead_id', $lead->id)->count());
    }

    public function test_upload_blocked_when_ccjs_parsed_but_county_court_judgment_creditor_missing(): void
    {
        $log = Log::spy();

        $fixtureText = $this->sanitizedFixtureText();
        $parsed = (new PdfCreditReportParser(new Parser()))->parseText($fixtureText);
        $this->app->instance(
            CreditReportParserFactory::class,
            new FixedPayloadCreditReportParserFactory($parsed)
        );

        $defaults = [
            'voting_house' => 'WATCH',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ];
        foreach ([
            'Could Not Match',
            'Bits Credit Card',
            'Fair Finance',
        ] as $name) {
            Creditor::firstOrCreate(['name' => $name], $defaults);
        }
        $this->assertNull(Creditor::where('name', 'County Court Judgment')->first());

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'credit-report-no-ccj-creditor-'.uniqid(),
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $response = $this->actingAs($user)->post('/lead/'.$lead->id.'/credit-reports', [
            'report_files' => [$file],
        ]);

        $response->assertRedirect('/lead/'.$lead->id.'#credit-report-upload');
        $response->assertSessionHas('credit_report_error');
        $response->assertSessionMissing('credit_report_success');

        $this->assertStringContainsStringIgnoringCase('County Court Judgment', (string) session('credit_report_error'));

        $this->assertDatabaseHas('credit_reports', [
            'lead_id' => $lead->id,
            'status' => 'failed',
        ]);

        $this->assertSame(0, Debt::where('lead_id', $lead->id)->count());

        $log->shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($lead): bool {
                return $message === 'Credit report import blocked: CCJs parsed but County Court Judgment creditor is missing'
                    && ($context['lead_id'] ?? null) === $lead->id;
            });
    }

    public function test_logs_notice_when_debt_assigned_to_could_not_match(): void
    {
        $log = Log::spy();

        $payload = [
            'text' => "Financial Account Information\nTotally Unknown Creditor XYZ Ltd £100 1 Jan 2026 Default\n",
            'debts' => [
                [
                    'creditor' => 'Totally Unknown Creditor XYZ Ltd',
                    'balance' => 100.0,
                    'status' => 'Default',
                ],
            ],
            'county_court_judgments' => [],
        ];
        $this->app->instance(
            CreditReportParserFactory::class,
            new FixedPayloadCreditReportParserFactory($payload)
        );

        $defaults = [
            'voting_house' => 'WATCH',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ];
        Creditor::firstOrCreate(['name' => 'Could Not Match'], $defaults);
        Creditor::firstOrCreate(['name' => 'County Court Judgment'], $defaults);

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'credit-report-cnm-'.uniqid(),
        ]);

        $file = UploadedFile::fake()->create('one.pdf', 5, 'application/pdf');

        $this->actingAs($user)->post('/lead/'.$lead->id.'/credit-reports', [
            'report_files' => [$file],
        ]);

        $log->shouldHaveReceived('notice')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Credit report debt assigned to Could Not Match'
                    && ($context['raw_creditor_name'] ?? null) === 'Totally Unknown Creditor XYZ Ltd';
            });
    }

    public function test_upload_binary_garbage_pdf_fails_cleanly_without_debts(): void
    {
        $this->seedCreditorsForSanitizedSample();

        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'credit-report-bad-'.uniqid(),
        ]);

        $file = UploadedFile::fake()->createWithContent('broken.pdf', "\x00\x01\x02\x03\x04");

        $response = $this->actingAs($user)->post('/lead/'.$lead->id.'/credit-reports', [
            'report_files' => [$file],
        ]);

        $response->assertRedirect('/lead/'.$lead->id.'#credit-report-upload');
        $response->assertSessionHas('credit_report_error');
        $response->assertSessionMissing('credit_report_success');

        $this->assertDatabaseHas('credit_reports', [
            'lead_id' => $lead->id,
            'status' => 'failed',
        ]);

        $this->assertSame(0, Debt::where('lead_id', $lead->id)->count());
    }
}
