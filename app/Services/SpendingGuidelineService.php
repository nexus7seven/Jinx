<?php

namespace App\Services;

class SpendingGuidelineService
{
    /**
     * @return array{min: float, max: float}
     */
    public function bounds(string $band, int $adults, int $childrenUnder16, int $children16To18): array
    {
        $coeffs = $this->coefficients($band);

        if ($coeffs === null) {
            return ['min' => 0.0, 'max' => 0.0];
        }

        $extraAdults = max(0, $adults - 1);

        return [
            'min' => $this->combine($coeffs, 'min', $extraAdults, $childrenUnder16, $children16To18),
            'max' => $this->combine($coeffs, 'max', $extraAdults, $childrenUnder16, $children16To18),
        ];
    }

    /**
     * Section maximum (legacy cap helper used by the existing I&E screen).
     *
     * @param  'comms_leisure'|'food'|'personal'|'comms'|'housekeeping'  $band
     */
    public function cap(string $band, int $adults, int $childrenUnder16, int $children16To18): float
    {
        return $this->bounds($band, $adults, $childrenUnder16, $children16To18)['max'];
    }

    /**
     * @return array<string, array{min: float, max: float}>|null
     */
    private function coefficients(string $band): ?array
    {
        $aliases = config('sfs_spending_guidelines.ui_cap_aliases', []);
        $key = $aliases[$band] ?? $band;
        $coeffs = config("sfs_spending_guidelines.bands.{$key}");

        return is_array($coeffs) ? $coeffs : null;
    }

    /**
     * @param  array<string, array{min: float|int, max: float|int}>  $coeffs
     */
    private function combine(array $coeffs, string $bound, int $extraAdults, int $childrenUnder16, int $children16To18): float
    {
        return (float) $coeffs['first_adult'][$bound]
            + $extraAdults * (float) $coeffs['additional_adult'][$bound]
            + $childrenUnder16 * (float) $coeffs['child_under_16'][$bound]
            + $children16To18 * (float) $coeffs['child_16_18'][$bound];
    }
}
