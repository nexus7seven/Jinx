<?php

namespace App\Services\CreditReports;

use App\Services\CreditReports\Parsers\CreditReportParserInterface;
use App\Services\CreditReports\Parsers\MhtCreditReportParser;
use App\Services\CreditReports\Parsers\PdfCreditReportParser;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

class CreditReportParserFactory
{
    public function make(string $type): CreditReportParserInterface
    {
        return match ($type) {
            CreditReportFileTypeDetector::TYPE_MHT => new MhtCreditReportParser(),
            CreditReportFileTypeDetector::TYPE_PDF => new PdfCreditReportParser(new Parser()),
            default => throw new InvalidArgumentException("Unsupported credit report type [{$type}]."),
        };
    }
}
