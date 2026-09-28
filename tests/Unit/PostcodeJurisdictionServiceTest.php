<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Services\PostcodeJurisdictionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostcodeJurisdictionServiceTest extends TestCase
{
    public function test_council_tax_lookup_uses_compact_postcode_and_unique_address(): void
    {
        Http::fake([
            'www.mycounciltax.org.uk/*' => Http::response($this->results([
                ['12, EXAMPLE ROAD, TESTVILLE, AB1 2CD', 'C', '£2401'],
                ['14, EXAMPLE ROAD, TESTVILLE, AB1 2CD', 'D', '£2800'],
            ])),
        ]);
        $lead = $this->lead();
        $lead->financial_statement = [
            'household' => ['partner_exists' => false, 'children' => []],
        ];

        $result = app(PostcodeJurisdictionService::class)->lookupCouncilTax($lead);

        $this->assertSame(2401.0, $result['annual_listed']);
        $this->assertSame(2401.0, $result['annual_after_discount']);
        $this->assertSame(201, $result['monthly']);
        $this->assertFalse($result['single_person_discount_applied']);
        Http::assertSent(
            fn ($request) => $request->url()
                === 'https://www.mycounciltax.org.uk/results?postcode=AB12CD'
        );
    }

    public function test_discount_requires_explicit_established_single_counting_adult(): void
    {
        Http::fake([
            'www.mycounciltax.org.uk/*' => Http::response($this->results([
                ['12, EXAMPLE ROAD, TESTVILLE, AB1 2CD', 'C', '£2401'],
            ])),
        ]);

        $result = app(PostcodeJurisdictionService::class)
            ->lookupCouncilTax($this->lead(), 1);

        $this->assertSame(1800.75, $result['annual_after_discount']);
        $this->assertSame(151, $result['monthly']);
        $this->assertTrue($result['single_person_discount_applied']);
    }

    public function test_ambiguous_or_missing_property_fails_closed(): void
    {
        $service = app(PostcodeJurisdictionService::class);
        Http::fake([
            'www.mycounciltax.org.uk/*' => Http::response($this->results([
                ['12, EXAMPLE ROAD, TESTVILLE, AB1 2CD', 'C', '£2401'],
                ['12, EXAMPLE ROAD, OTHER AREA, AB1 2CD', 'D', '£2800'],
            ])),
        ]);
        $this->assertNull($service->lookupCouncilTax($this->lead()));

        Http::fake([
            'www.mycounciltax.org.uk/*' => Http::response($this->results([
                ['14, EXAMPLE ROAD, TESTVILLE, AB1 2CD', 'D', '£2800'],
            ])),
        ]);
        $this->assertNull($service->lookupCouncilTax($this->lead()));
    }

    public function test_fetch_or_malformed_input_fails_closed(): void
    {
        $service = app(PostcodeJurisdictionService::class);
        Http::fake(['www.mycounciltax.org.uk/*' => Http::response('', 503)]);
        $this->assertNull($service->lookupCouncilTax($this->lead()));

        $lead = $this->lead();
        $lead->postcode = 'not a postcode';
        $this->assertNull($service->lookupCouncilTax($lead));
        Http::assertSentCount(1);
    }

    public function test_conflicting_house_number_and_address_fails_closed(): void
    {
        Http::fake([
            'www.mycounciltax.org.uk/*' => Http::response($this->results([
                ['12, EXAMPLE ROAD, TESTVILLE, AB1 2CD', 'C', '£2401'],
            ])),
        ]);
        $lead = $this->lead();
        $lead->house_number = '14';
        $lead->address_line_1 = '12 Example Road';

        $this->assertNull(
            app(PostcodeJurisdictionService::class)->lookupCouncilTax($lead)
        );
    }

    private function lead(): Lead
    {
        return new Lead([
            'house_number' => '12',
            'address_line_1' => 'Example Road',
            'postcode' => 'ab1 2cd',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $rows
     */
    private function results(array $rows): string
    {
        $body = '<table><thead><tr><th>Address</th><th>Council tax band</th>'
            .'<th>Annual council tax</th></tr></thead><tbody>';
        foreach ($rows as [$address, $band, $annual]) {
            $body .= '<tr><td>'.htmlspecialchars($address).'</td><td>'.$band
                .'</td><td>'.$annual.'</td></tr>';
        }

        return $body.'</tbody></table>';
    }
}
