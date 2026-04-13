<?php

namespace App\Support;

/**
 * Central place for human-readable lead source labels (WIP pills, detail, future Teams).
 */
final class LeadSourceDisplay
{
    public static function label(?string $source): string
    {
        $s = trim((string) $source);
        if ($s === '') {
            return 'Unknown';
        }

        return match ($s) {
            'WEBSITE-CLEARMYCREDIT' => 'Website (Clear My Credit)',
            default => $s,
        };
    }
}
