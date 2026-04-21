<?php

namespace App\Services;

use App\Models\LocalBrowserJob;

class LocalWorkerTempMailService
{
    public function generate(LocalBrowserJob $job): array
    {
        $service = new TempMailService();
        $inbox = $service->createInboxUsingRandomDomain();
        $email = (string) ($inbox['email'] ?? '');

        if ($email === '') {
            throw new \RuntimeException('No email address returned by temp-mail provider.');
        }

        $meta = [
            'provider' => config('services.temp_mail.provider', 'tempmailio'),
            'ttl' => $inbox['ttl'] ?? null,
            'generated_at' => now()->toIso8601String(),
        ];

        $job->fill([
            'temp_email_address' => $email,
            'temp_email_meta_json' => $meta,
            'latest_email_code' => null,
        ]);
        $job->save();

        return [
            'email' => $email,
            'meta' => $meta,
        ];
    }

    public function latestCode(LocalBrowserJob $job, ?string $pattern = null): array
    {
        $email = (string) ($job->temp_email_address ?? '');
        if ($email === '') {
            return [
                'found' => false,
                'code' => null,
                'email' => null,
                'message' => 'No temp email assigned to job.',
            ];
        }

        $service = new TempMailService();
        $messagesResponse = $service->getMessages($email);
        $messages = $service->normaliseMessages($messagesResponse);
        $latest = $service->pickLatestMessage($messages);

        if (! $latest || empty($latest['id'])) {
            return [
                'found' => false,
                'code' => null,
                'email' => $email,
                'message' => 'No messages yet.',
            ];
        }

        $full = $service->getMessage((string) $latest['id']);
        $bodyText = (string) ($full['body_text'] ?? '');
        $subject = (string) ($full['subject'] ?? ($latest['subject'] ?? ''));
        $from = (string) ($full['from'] ?? ($latest['from'] ?? ''));
        $code = $service->extractSpecificAuthCode($bodyText, $pattern);

        if ($code !== null && $code !== '') {
            $job->latest_email_code = $code;
            $job->save();
        }

        return [
            'found' => $code !== null && $code !== '',
            'code' => $code,
            'email' => $email,
            'message_id' => (string) $latest['id'],
            'subject' => $subject,
            'from' => $from,
            'created_at' => $full['created_at'] ?? ($latest['created_at'] ?? null),
        ];
    }
}
