<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PartnerKnowledgeService
{
    public function profile(?string $source, array $facts = []): array
    {
        $sourceText = Str::lower((string) $source);
        $partner = null;

        if (str_contains($sourceText, 'zebra')) {
            $partner = 'Zebra';
        } elseif (str_contains($sourceText, 'avondale')) {
            $partner = 'Avondale';
        }

        $ip = $facts['case.ip_destination'] ?? $facts['case.ip'] ?? null;
        $ip = is_string($ip) && trim($ip) !== '' ? trim($ip) : null;

        return [
            'partner' => $partner,
            'ip' => $ip,
            'partner_codex' => $partner ? $this->loadPartnerCodex($partner) : null,
            'precedence' => ['ip', 'partner', 'company'],
        ];
    }

    public function loadPartnerCodex(string $partner): ?string
    {
        $directory = match (Str::lower($partner)) {
            'zebra' => resource_path('assistant/knowledge/zebra'),
            'avondale' => resource_path('assistant/knowledge/avondale'),
            default => null,
        };

        if (! $directory || ! File::isDirectory($directory)) {
            return null;
        }

        $parts = [];
        foreach (collect(File::files($directory))->sortBy(fn ($file) => $file->getFilename()) as $file) {
            if (Str::lower($file->getExtension()) !== 'md') {
                continue;
            }
            $parts[] = "\n\n===== {$file->getFilename()} =====\n\n".File::get($file->getPathname());
        }

        return trim(implode('', $parts));
    }
}
