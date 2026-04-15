<?php

namespace App\Services;

use Twilio\Rest\Client;
use Throwable;

class SmsService
{
    public function sendSms(string $to, string $message): bool
    {
        $sid = (string) env('TWILIO_ACCOUNT_SID', '');
        $token = (string) env('TWILIO_AUTH_TOKEN', '');
        $from = $this->formatUkPhone((string) env('TWILIO_FROM_NUMBER', ''));
        $toNumber = $this->formatUkPhone($to);
        $body = trim($message);

        if ($sid === '' || $token === '' || $from === null || $toNumber === null || $body === '') {
            return false;
        }

        try {
            $client = new Client($sid, $token);
            $client->messages->create($toNumber, [
                'from' => $from,
                'body' => $body,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function formatUkPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '44')) {
            return '+44' . substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '+44' . substr($digits, 1);
        }

        return '+44' . ltrim($digits, '0');
    }
}
