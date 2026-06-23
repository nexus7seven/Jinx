<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerPortalLeadExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_can_export_own_leads_filtered_only_by_date(): void
    {
        $partner = Partner::create([
            'name' => 'Outer Partner',
            'token' => 'outer-partner',
            'active' => true,
            'portal_email' => 'partner@example.com',
            'portal_password' => bcrypt('password'),
            'portal_access_enabled' => true,
        ]);

        Lead::create([
            'vicidial_lead_id' => 'export-included',
            'phone_number' => '07111111111',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'source' => $partner->name,
            'submitted_by_vicidial_user' => 'Alice',
            'wip_status' => 'Sale',
            'lead_feedback' => 'Good feedback',
            'case_notes' => 'Submitted notes',
            'created_at' => '2026-06-10 14:30:15',
            'updated_at' => '2026-06-10 14:30:15',
        ]);

        Lead::create([
            'vicidial_lead_id' => 'export-ignored-status',
            'phone_number' => '07222222222',
            'first_name' => 'John',
            'last_name' => 'Smith',
            'source' => $partner->name,
            'submitted_by_vicidial_user' => 'Bob',
            'wip_status' => 'WIP',
            'created_at' => '2026-06-11 09:00:00',
            'updated_at' => '2026-06-11 09:00:00',
        ]);

        Lead::create([
            'vicidial_lead_id' => 'export-out-of-range',
            'phone_number' => '07333333333',
            'first_name' => 'Date',
            'last_name' => 'Outside',
            'source' => $partner->name,
            'created_at' => '2026-05-31 23:59:59',
            'updated_at' => '2026-05-31 23:59:59',
        ]);

        Lead::create([
            'vicidial_lead_id' => 'export-other-partner',
            'phone_number' => '07444444444',
            'first_name' => 'Other',
            'last_name' => 'Partner',
            'source' => 'Another Partner',
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ]);

        $response = $this->withSession(['partner_portal_partner_id' => $partner->id])
            ->get('/partner-portal/leads/export?from=2026-06-01&to=2026-06-30&status=DEAD&submitted_by=Nobody');

        $response->assertOk();
        $response->assertDownload('outerorbit_leads_2026-06-01_to_2026-06-30.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Customer Name,"Date Received",Phone,"Submitted By","Case Status","Lead Feedback","Submitted Notes"', $csv);
        $this->assertStringContainsString('Jane Doe,"10/06/2026 14:30:15",07111111111,Alice,Sale,"Good feedback","Submitted notes"', $csv);
        $this->assertStringContainsString('John Smith,"11/06/2026 09:00:00",07222222222,Bob,WIP,,', $csv);
        $this->assertStringNotContainsString('Date Outside', $csv);
        $this->assertStringNotContainsString('Other Partner', $csv);
    }
}
