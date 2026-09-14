<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ZebraCodexService
{
    private const FILES = [
        '01_eligibility.md',
        '02_income_and_income_calculations.md',
        '03_expenditure_I&E_calculation_rules.md',
    ];

    public function appliesTo(?string $source): bool
    {
        return str_contains(strtolower((string) $source), 'zebra');
    }

    public function load(): string
    {
        $base = resource_path('assistant/knowledge/zebra');
        $parts = [];

        foreach (self::FILES as $file) {
            $path = $base.DIRECTORY_SEPARATOR.$file;
            if (File::exists($path)) {
                $parts[] = "\n\n===== {$file} =====\n\n".File::get($path);
            }
        }

        return trim(implode('', $parts));
    }
}
