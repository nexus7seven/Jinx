<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\DeckardCallbackClient;
use App\Support\VicidialDialPhone;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ClickToCallController extends Controller
{
    public function __construct(
        private DeckardCallbackClient $deckard,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
        ]);

        $lead = Lead::query()->findOrFail($validated['lead_id']);

        $national = VicidialDialPhone::nationalDigits($lead->phone_number);
        if ($national === null || $national === '') {
            return response()->json($this->errorPayload(
                'no_phone_number',
                'Lead has no usable phone number.'
            ), 422);
        }

        $vicidialLeadId = (int) ($lead->vicidial_lead_id ?? 0);

        try {
            $response = $this->deckard->postDirectDial($vicidialLeadId, $national);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'deckard_callback_not_configured') {
                return response()->json($this->errorPayload(
                    'deckard_callback_not_configured',
                    'Set DECKARD_CALLBACK_URL in Jinx .env'
                ), 503);
            }

            throw $e;
        } catch (Throwable $e) {
            Log::error('Deckard click-to-call: request exception', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $vicidialLeadId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            report($e);

            return response()->json($this->errorPayload(
                'deckard_request_failed',
                'Could not reach Deckard callback endpoint.'
            ), 502);
        }

        $json = $response->json();
        if (! is_array($json)) {
            Log::error('Deckard click-to-call: response is not JSON', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $vicidialLeadId,
                'http_status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 2000),
            ]);

            return response()->json($this->errorPayload(
                'invalid_deckard_response',
                'Deckard returned an invalid response.'
            ), 502);
        }

        $this->logDeckardOutcome($lead->id, $vicidialLeadId, $response, $json);

        $status = $response->status();
        if ($status < 200 || $status >= 300) {
            return response()->json(
                $this->enrichDeckardPayload($json),
                $status >= 400 && $status < 600 ? $status : 502
            );
        }

        return response()->json($this->enrichDeckardPayload($json));
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function logDeckardOutcome(int $jinxLeadId, int $vicidialLeadId, Response $response, array $json): void
    {
        $ok = (bool) ($json['ok'] ?? false);

        if (! $ok) {
            Log::warning('Deckard click-to-call: Deckard reported failure', [
                'jinx_lead_id' => $jinxLeadId,
                'vicidial_lead_id' => $vicidialLeadId,
                'http_status' => $response->status(),
                'deckard' => $json,
            ]);

            return;
        }

        if (($json['popup_confirmed'] ?? null) === false) {
            Log::info('Deckard click-to-call: dial ok but popup not confirmed', [
                'jinx_lead_id' => $jinxLeadId,
                'vicidial_lead_id' => $vicidialLeadId,
                'deckard_lead_id' => $json['lead_id'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function enrichDeckardPayload(array $json): array
    {
        if (! isset($json['message'])) {
            if (isset($json['error']) && is_string($json['error'])) {
                $json['message'] = $json['error'];
            } elseif (isset($json['details']) && is_string($json['details'])) {
                $json['message'] = $json['details'];
            }
        }

        return $json;
    }

    /**
     * @return array{ok: false, error: string, message: string}
     */
    private function errorPayload(string $error, string $message): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ];
    }
}
