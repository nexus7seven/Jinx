<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppDetectorEventIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppDetectorEventApiController extends Controller
{
    public function __invoke(Request $request, WhatsAppDetectorEventIngestor $ingestor): JsonResponse
    {
        if ($request->isJson()) {
            $decoded = $request->json()->all();
        } else {
            $raw = $request->getContent();
            $decoded = $raw !== '' ? json_decode($raw, true) : [];
        }

        if (! is_array($decoded)) {
            return response()->json([
                'status' => 'invalid_payload',
                'event_id' => null,
                'match_status' => null,
                'is_after_flow_start' => null,
            ]);
        }

        $result = $ingestor->ingestPayload($decoded);

        return response()->json([
            'status' => $result['status'],
            'event_id' => $result['event_id'],
            'match_status' => $result['match_status'] ?? null,
            'is_after_flow_start' => array_key_exists('is_after_flow_start', $result)
                ? $result['is_after_flow_start']
                : null,
        ]);
    }
}
