<?php

namespace App\Services\CreditReports;

use Illuminate\Http\UploadedFile;

class CreditReportFileTypeDetector
{
    public const TYPE_MHT = 'mht';
    public const TYPE_PDF = 'pdf';

    public function detect(UploadedFile $file): ?string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mimeType = strtolower((string) $file->getMimeType());
        $clientMimeType = strtolower((string) $file->getClientMimeType());

        $allMimes = array_filter([$mimeType, $clientMimeType]);

        if ($extension === 'pdf' || $this->hasMime($allMimes, ['application/pdf'])) {
            return self::TYPE_PDF;
        }

        if (in_array($extension, ['mht', 'mhtml'], true) || $this->hasMime($allMimes, [
            'message/rfc822',
            'multipart/related',
            'application/octet-stream',
            'text/plain',
            'application/x-mimearchive',
        ])) {
            return self::TYPE_MHT;
        }

        return null;
    }

    /**
     * @param array<int, string> $mimes
     * @param array<int, string> $allowed
     */
    private function hasMime(array $mimes, array $allowed): bool
    {
        foreach ($mimes as $mime) {
            if (in_array($mime, $allowed, true)) {
                return true;
            }
        }

        return false;
    }
}
