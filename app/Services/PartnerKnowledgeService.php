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

        $ip = $this->canonicalIp($facts['case.ip_destination'] ?? $facts['case.ip'] ?? null);
        $partnerCodex = $partner ? $this->loadPartnerCodex($partner) : null;
        $ipCodex = ($partner === 'Avondale' && $ip) ? $this->loadAvondaleIpCodex($ip) : null;

        return [
            'partner' => $partner,
            'ip' => $ip,
            'partner_codex' => $this->combineCodex($partnerCodex, $ipCodex),
            'ip_codex' => $ipCodex,
            'precedence' => ['ip', 'partner', 'company'],
        ];
    }

    public function comparisonCatalogue(): array
    {
        $avondale = $this->loadPartnerCodex('Avondale');

        return [
            [
                'destination' => 'Zebra',
                'partner' => 'Zebra',
                'ip' => 'Zebra',
                'codex' => $this->loadPartnerCodex('Zebra'),
            ],
            [
                'destination' => 'Lawson Fox',
                'partner' => 'Avondale',
                'ip' => 'Lawson Fox',
                'codex' => $this->combineCodex($avondale, $this->loadAvondaleIpCodex('Lawson Fox')),
            ],
            [
                'destination' => 'TIG',
                'partner' => 'Avondale',
                'ip' => 'TIG',
                'codex' => $this->combineCodex($avondale, $this->loadAvondaleIpCodex('TIG')),
            ],
            [
                'destination' => 'Assure',
                'partner' => 'Avondale',
                'ip' => 'Assure',
                'codex' => $this->combineCodex($avondale, $this->loadAvondaleIpCodex('Assure')),
            ],
            [
                'destination' => 'Anchorage Chambers',
                'partner' => 'Avondale',
                'ip' => 'Anchorage Chambers',
                'codex' => $this->combineCodex($avondale, $this->loadAvondaleIpCodex('Anchorage Chambers')),
            ],
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

        return $this->loadMarkdownDirectory($directory);
    }

    public function loadAvondaleIpCodex(string $ip): ?string
    {
        $slug = match ($this->canonicalIp($ip)) {
            'Lawson Fox' => 'lawson-fox',
            'TIG' => 'tig',
            'Assure' => 'assure',
            'Anchorage Chambers' => 'anchorage-chambers',
            default => null,
        };

        if (! $slug) {
            return null;
        }

        $directory = resource_path('assistant/knowledge/avondale/ips/'.$slug);
        if (! File::isDirectory($directory)) {
            return null;
        }

        return $this->loadMarkdownDirectory($directory);
    }

    private function combineCodex(?string $partnerCodex, ?string $ipCodex): ?string
    {
        $parts = array_values(array_filter([
            $partnerCodex ? "===== PARTNER-LEVEL CODEX =====\n\n{$partnerCodex}" : null,
            $ipCodex ? "===== IP-SPECIFIC CODEX =====\n\n{$ipCodex}" : null,
        ]));

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function loadMarkdownDirectory(string $directory): ?string
    {
        $parts = [];
        foreach (collect(File::files($directory))->sortBy(fn ($file) => $file->getFilename()) as $file) {
            if (Str::lower($file->getExtension()) !== 'md') {
                continue;
            }
            $parts[] = "\n\n===== {$file->getFilename()} =====\n\n".File::get($file->getPathname());
        }

        $content = trim(implode('', $parts));
        return $content !== '' ? $content : null;
    }

    private function canonicalIp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalised = Str::lower(trim($value));

        return match (true) {
            str_contains($normalised, 'lawson') => 'Lawson Fox',
            $normalised === 'tig' || str_contains($normalised, 'tig ') || str_contains($normalised, ' tig') => 'TIG',
            str_contains($normalised, 'assure') => 'Assure',
            str_contains($normalised, 'anchorage') || $normalised === 'ac' => 'Anchorage Chambers',
            default => trim($value),
        };
    }
}
