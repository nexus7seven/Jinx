<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppBridgeClient
{
    public function queueSendJob(array $payload): array
    {
        $enabled = (bool) config('services.whatsapp_bridge.enabled', false);
        $baseUrl = rtrim((string) config('services.whatsapp_bridge.base_url', ''), '/');
        $timeout = (int) config('services.whatsapp_bridge.timeout_seconds', 10);

        if (! $enabled) {
            return [
                'queued' => false,
                'status_code' => null,
                'response' => null,
                'error' => 'whatsapp_bridge_disabled',
            ];
        }

        if ($baseUrl === '') {
            return [
                'queued' => false,
                'status_code' => null,
                'response' => null,
                'error' => 'whatsapp_bridge_base_url_missing',
            ];
        }

        try {
            $response = Http::timeout(max(1, $timeout))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/extension/send-jobs', $payload);

            $decoded = $response->json();
            $body = is_array($decoded) ? $decoded : $response->body();

            return [
                'queued' => $response->successful(),
                'status_code' => $response->status(),
                'response' => $body,
                'error' => $response->successful() ? null : 'whatsapp_bridge_queue_failed',
            ];
        } catch (\Throwable $e) {
            Log::warning('[whatsapp-bridge] queue send job failed', [
                'error' => $e->getMessage(),
                'base_url' => $baseUrl,
                'payload_id' => $payload['id'] ?? null,
            ]);

            return [
                'queued' => false,
                'status_code' => null,
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function fetchSendResults(int $limit = 50): array
    {
        $enabled = (bool) config('services.whatsapp_bridge.enabled', false);
        $baseUrl = rtrim((string) config('services.whatsapp_bridge.base_url', ''), '/');
        $timeout = (int) config('services.whatsapp_bridge.timeout_seconds', 10);

        if (! $enabled) {
            return [
                'ok' => false,
                'status_code' => null,
                'response' => null,
                'error' => 'whatsapp_bridge_disabled',
            ];
        }

        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status_code' => null,
                'response' => null,
                'error' => 'whatsapp_bridge_base_url_missing',
            ];
        }

        try {
            $response = Http::timeout(max(1, $timeout))
                ->acceptJson()
                ->get($baseUrl.'/extension/send-results', [
                    'limit' => max(1, $limit),
                ]);

            $decoded = $response->json();
            $body = is_array($decoded) ? $decoded : $response->body();

            return [
                'ok' => $response->successful(),
                'status_code' => $response->status(),
                'response' => $body,
                'error' => $response->successful() ? null : 'whatsapp_bridge_fetch_results_failed',
            ];
        } catch (\Throwable $e) {
            Log::warning('[whatsapp-bridge] fetch send results failed', [
                'error' => $e->getMessage(),
                'base_url' => $baseUrl,
                'limit' => $limit,
            ]);

            return [
                'ok' => false,
                'status_code' => null,
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
