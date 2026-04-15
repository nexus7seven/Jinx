<?php

namespace Tests\Unit;

use App\Services\CreditReports\CreditReportFileTypeDetector;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CreditReportFileTypeDetectorTest extends TestCase
{
    public function test_detects_pdf_from_extension_and_mime(): void
    {
        $detector = new CreditReportFileTypeDetector();
        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $this->assertSame(CreditReportFileTypeDetector::TYPE_PDF, $detector->detect($file));
    }

    public function test_detects_mht_from_extension(): void
    {
        $detector = new CreditReportFileTypeDetector();
        $file = UploadedFile::fake()->create('report.mht', 100, 'application/octet-stream');

        $this->assertSame(CreditReportFileTypeDetector::TYPE_MHT, $detector->detect($file));
    }

    public function test_returns_null_for_unsupported_file_type(): void
    {
        $detector = new CreditReportFileTypeDetector();
        $file = UploadedFile::fake()->create('report.txt', 100, 'text/plain');

        $this->assertNull($detector->detect($file));
    }
}
