<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * HTTP client for Deckard /var/www/html/api/callback_action.php (direct_dial, dial, etc.).
 */
class DeckardCallbackClient
{
    public function postDirectDial(int $vicidialLeadId, string $nationalDigits): Response
    {
        $url = config('services.deckard.callback_url');
        if ($url === null || $url === '') {
            throw new InvalidArgumentException('deckard_callback_not_configured');
        }

        return Http::timeout(60)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'action' => 'direct_dial',
                'lead_id' => $vicidialLeadId,
                'phone_number' => $nationalDigits,
            ]);
    }
}
