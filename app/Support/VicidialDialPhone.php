<?php

namespace App\Support;

/**
 * UK numbers for VICIdial external_dial "value" + phone_code 44.
 *
 * Rules MUST match deckard/callback_action.php `deckard_normalize_uk_national()` on the Deckard server.
 * If you change one, update the other.
 */
final class VicidialDialPhone
{
    /**
     * National digits only (no country code, no leading 0), suitable for Deckard `direct_dial` payload
     * and external_dial `value` (same string Jinx sends and callback_action.php re-normalizes).
     */
    public static function nationalDigits(?string $raw): ?string
    {
        $raw = $raw ?? '';
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        // International prefix without +: 0044… must run before the generic leading-0 rule.
        if (str_starts_with($digits, '0044')) {
            $rest = substr($digits, 4);
            if ($rest === '') {
                return null;
            }
            if (str_starts_with($rest, '0')) {
                return substr($rest, 1);
            }

            return $rest;
        }

        if (str_starts_with($digits, '44') && strlen($digits) > 10) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }
}
