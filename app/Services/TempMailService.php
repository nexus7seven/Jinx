<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TempMailService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.temp_mail.base_url'), '/');
        $this->apiKey = (string) config('services.temp_mail.key');

        if ($this->apiKey === '') {
            throw new RuntimeException('TEMP_MAIL_API_KEY is missing.');
        }
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $response = Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(20)->$method($url, $data);

        $this->throwIfBadResponse($response);

        return $response->json() ?? [];
    }

    protected function throwIfBadResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();

        $detail = $body['error']['detail']
            ?? $body['message']
            ?? $response->body();

        throw new RuntimeException('Temp mail API error: ' . $detail);
    }

    public function listDomains(): array
    {
        return $this->request('get', '/v1/domains');
    }

    public function createInbox(?string $email = null, ?string $domain = null): array
    {
        $payload = [];

        if ($email) {
            $payload['email'] = $email;
        } elseif ($domain) {
            $payload['domain'] = $domain;
        }

        return $this->request('post', '/v1/emails', $payload);
    }

    public function createRandomInbox(): array
    {
        return $this->createInbox();
    }

    public function createInboxUsingRandomDomain(): array
    {
        $domainsResponse = $this->listDomains();

        $domains = $domainsResponse['domains'] ?? $domainsResponse ?? [];

        if (!is_array($domains) || count($domains) === 0) {
            return $this->createRandomInbox();
        }

        $picked = $domains[array_rand($domains)];

        $domainName = is_array($picked)
            ? ($picked['name'] ?? $picked['domain'] ?? null)
            : null;

        if (!$domainName) {
            return $this->createRandomInbox();
        }

        return $this->createInbox(null, $domainName);
    }

    public function getMessages(string $email): array
    {
        $encoded = rawurlencode($email);

        return $this->request('get', "/v1/emails/{$encoded}/messages");
    }

    public function getMessage(string $messageId): array
    {
        $encoded = rawurlencode($messageId);

        return $this->request('get', "/v1/messages/{$encoded}");
    }

    public function deleteEmail(string $email): array
    {
        $encoded = rawurlencode($email);

        return $this->request('delete', "/v1/emails/{$encoded}");
    }

    public function extractSpecificAuthCode(string $text, ?string $pattern = null): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if ($pattern && @preg_match($pattern, $text, $m)) {
            if (!empty($m[1])) {
                return trim($m[1], " \t\n\r\0\x0B*");
            }

            if (!empty($m[0])) {
                return trim($m[0], " \t\n\r\0\x0B*");
            }
        }

        $patterns = [
            '/\*+\s*([0-9]{4,10})\s*\*+/',
            '/authentication code\s*(?:is|:)?\s*\*?\s*([0-9]{4,10})\s*\*?/i',
            '/verification code\s*(?:is|:)?\s*\*?\s*([0-9]{4,10})\s*\*?/i',
            '/security code\s*(?:is|:)?\s*\*?\s*([0-9]{4,10})\s*\*?/i',
            '/one[-\s]?time pass(?:word|code)?\s*(?:is|:)?\s*\*?\s*([0-9]{4,10})\s*\*?/i',
            '/\b([0-9]{4,10})\b/',
        ];

        foreach ($patterns as $regex) {
            if (preg_match($regex, $text, $m)) {
                return trim($m[1] ?? $m[0] ?? '', " \t\n\r\0\x0B*");
            }
        }

        return null;
    }

    public function extractFirstLink(string $text): ?string
    {
        if (preg_match('/https?:\/\/[^\s"<>()]+/i', $text, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    public function normaliseMessages(array $response): array
    {
        return $response['messages'] ?? $response ?? [];
    }

    public function pickLatestMessage(array $messages): ?array
    {
        if (empty($messages)) {
            return null;
        }

        usort($messages, function ($a, $b) {
            return strtotime($b['created_at'] ?? '1970-01-01 00:00:00')
                <=> strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
        });

        return $messages[0] ?? null;
    }

    public function generateSafeLocalPart(int $length = 10): string
    {
        return strtolower(Str::random($length));
    }
}