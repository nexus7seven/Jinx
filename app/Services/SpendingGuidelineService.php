<?php

namespace App\Services;

class SpendingGuidelineService
{
    /**
     * @param  'comms_leisure'|'food'|'personal'  $band
     */
    public function cap(string $band, int $adults, int $childrenUnder16, int $children16To18): float
    {
        $coeffs = config("sfs_spending_guidelines.bands.{$band}");

        if (!is_array($coeffs)) {
            return 0.0;
        }

        $extraAdults = max(0, $adults - 1);

        return round(
            (float) $coeffs['base_one_adult']
            + $extraAdults * (float) $coeffs['additional_adult']
            + $childrenUnder16 * (float) $coeffs['child_under_16']
            + $children16To18 * (float) $coeffs['child_16_18'],
            2
        );
    }
}
